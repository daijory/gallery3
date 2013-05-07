<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2013 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Gallery_L10nClient {

  protected static function _server_url($path) {
    return "http://galleryproject.org/translations/$path";
  }

  static function server_api_key_url() {
    return self::_server_url("userkey/" . self::client_token());
  }

  static function client_token() {
    return md5("l10n_client_client_token" . Access::private_key());
  }

  static function api_key($api_key=null) {
    if ($api_key !== null) {
      Module::set_var("gallery", "l10n_client_key", $api_key);
    }
    return Module::get_var("gallery", "l10n_client_key", "");
  }

  static function server_uid($api_key=null) {
    $api_key = $api_key == null ? L10nClient::api_key() : $api_key;
    $parts = explode(":", $api_key);
    return empty($parts) ? 0 : $parts[0];
  }

  protected static function _sign($payload, $api_key=null) {
    $api_key = $api_key == null ? L10nClient::api_key() : $api_key;
    return md5($api_key . $payload . L10nClient::client_token());
  }

  static function validate_api_key($api_key) {
    $version = "1.0";
    $url = self::_server_url("status");
    $signature = self::_sign($version, $api_key);

    try {
      list ($response_data, $response_status) = Remote::post(
        $url, array("version" => $version,
                    "client_token" => L10nClient::client_token(),
                    "signature" => $signature,
                    "uid" => L10nClient::server_uid($api_key)));
    } catch (ErrorException $e) {
      // Log the error, but then return a "can't make connection" error
      Log::instance()->add(Log::ERROR, $e->getMessage() . "\n" . $e->getTraceAsString());
    }
    if (!isset($response_data) && !isset($response_status)) {
      return array(false, false);
    }

    if (!Remote::success($response_status)) {
      return array(true, false);
    }
    return array(true, true);
  }

  /**
   * Fetches translations for l10n messages. Must be called repeatedly
   * until 0 is returned (which is a countdown indicating progress).
   *
   * @param $num_fetched in/out parameter to specify which batch of
   *     messages to fetch translations for.
   * @return The number of messages for which we didn't fetch
   *     translations for.
   */
  static function fetch_updates(&$num_fetched) {
    $request = new stdClass();
    $request->locales = array();
    $request->messages = new stdClass();

    $locales = Locales::installed();
    foreach ($locales as $locale => $locale_data) {
      $request->locales[] = $locale;
    }

    // See the server side code for how we arrive at this
    // number as a good limit for #locales * #messages.
    $max_messages = 2000 / count($locales);
    $num_messages = 0;
    $rows = DB::select("key", "locale", "revision", "translation")
      ->from("incoming_translations")
      ->order_by("key")
      ->limit(1000000)  // ignore, just there to satisfy SQL syntax
      ->offset($num_fetched)
      ->as_object()
      ->execute();
    $num_remaining = $rows->count();
    foreach ($rows as $row) {
      if (!isset($request->messages->{$row->key})) {
        if ($num_messages >= $max_messages) {
          break;
        }
        $request->messages->{$row->key} = 1;
        $num_messages++;
      }
      if (!empty($row->revision) && !empty($row->translation) &&
          isset($locales[$row->locale])) {
        if (!is_object($request->messages->{$row->key})) {
          $request->messages->{$row->key} = new stdClass();
        }
        $request->messages->{$row->key}->{$row->locale} = (int) $row->revision;
      }
      $num_fetched++;
      $num_remaining--;
    }
    // @todo Include messages from outgoing_translations?

    if (!$num_messages) {
      return $num_remaining;
    }

    $request_data = json_encode($request);
    $url = self::_server_url("fetch");
    list ($response_data, $response_status) = Remote::post($url, array("data" => $request_data));
    if (!Remote::success($response_status)) {
      throw new Gallery_Exception("Translations fetch request failed: $response_status");
    }
    if (empty($response_data)) {
      return $num_remaining;
    }

    $response = json_decode($response_data);

    // Response format (JSON payload):
    //   [{key:<key_1>, translation: <JSON encoded translation>, rev:<rev>, locale:<locale>},
    //    {key:<key_2>, ...}
    //   ]
    foreach ($response as $message_data) {
      // @todo Better input validation
      if (empty($message_data->key) || empty($message_data->translation) ||
          empty($message_data->locale) || empty($message_data->rev)) {
        throw new Gallery_Exception("Translations fetch request failed: invalid response data");
      }
      $key = $message_data->key;
      $locale = $message_data->locale;
      $revision = $message_data->rev;
      $translation = json_decode($message_data->translation);
      if (!is_string($translation)) {
        // Normalize stdclass to array
        $translation = (array) $translation;
      }
      $translation = serialize($translation);

      // @todo Should we normalize the incoming_translations table into messages(id, key, message)
      // and incoming_translations(id, translation, locale, revision)? Or just allow
      // incoming_translations.message to be NULL?
      $locale = $message_data->locale;
      $entry = ORM::factory("IncomingTranslation")
        ->where("key", "=", $key)
        ->where("locale", "=", $locale)
        ->find();
      if (!$entry->loaded()) {
        // @todo Load a message key -> message (text) dict into memory outside of this loop
        $root_entry = ORM::factory("IncomingTranslation")
          ->where("key", "=", $key)
          ->where("locale", "=", "root")
          ->find();
        $entry->key = $key;
        $entry->message = $root_entry->message;
        $entry->locale = $locale;
      }
      $entry->revision = $revision;
      $entry->translation = $translation;
      $entry->save();
    }

    return $num_remaining;
  }

  static function submit_translations() {
    // Request format (HTTP POST):
    //   client_token = <client_token>
    //   uid = <l10n server user id>
    //   signature = md5(user_api_key($uid, $client_token) . $data . $client_token))
    //   data = // JSON payload
    //
    //     {<key_1>: {message: <JSON encoded message>
    //                translations: {<locale_1>: <JSON encoded translation>,
    //                               <locale_2>: ...}},
    //      <key_2>: {...}
    //     }

    // @todo Batch requests (max request size)
    // @todo include base_revision in submission / how to handle resubmissions / edit fights?
    $request = new stdClass();
    foreach (DB::select("key", "message", "locale", "base_revision", "translation")
             ->from("outgoing_translations")
             ->as_object()
             ->execute() as $row) {
      $key = $row->key;
      if (!isset($request->{$key})) {
        $request->{$key} = new stdClass();
        $request->{$key}->translations = new stdClass();
        $request->{$key}->message = json_encode(unserialize($row->message));
      }
      $request->{$key}->translations->{$row->locale} = json_encode(unserialize($row->translation));
    }

    // @todo reduce memory consumption, e.g. free $request
    $request_data = json_encode($request);
    $url = self::_server_url("submit");
    $signature = self::_sign($request_data);

    list ($response_data, $response_status) = Remote::post(
      $url, array("data" => $request_data,
                  "client_token" => L10nClient::client_token(),
                  "signature" => $signature,
                  "uid" => L10nClient::server_uid()));

    if (!Remote::success($response_status)) {
      throw new Gallery_Exception("Translations submission failed: $response_status");
    }
    if (empty($response_data)) {
      return;
    }

    $response = json_decode($response_data);
    // Response format (JSON payload):
    //   [{key:<key_1>, locale:<locale_1>, rev:<rev_1>, status:<rejected|accepted|pending>},
    //    {key:<key_2>, ...}
    //   ]

    // @todo Move messages out of outgoing into incoming, using new rev?
    // @todo show which messages have been rejected / are pending?
  }

  /**
   * Plural forms.
   */
  static function plural_forms($locale) {
    $parts = explode('_', $locale);
    $language = $parts[0];

    // Data from CLDR 1.6 (http://unicode.org/cldr/data/common/supplemental/plurals.xml).
    // Docs: http://www.unicode.org/cldr/data/charts/supplemental/language_plural_rules.html
    switch ($language) {
      case 'az':
      case 'fa':
      case 'hu':
      case 'ja':
      case 'ko':
      case 'my':
      case 'to':
      case 'tr':
      case 'vi':
      case 'yo':
      case 'zh':
      case 'bo':
      case 'dz':
      case 'id':
      case 'jv':
      case 'ka':
      case 'km':
      case 'kn':
      case 'ms':
      case 'th':
        return array('other');

      case 'ar':
        return array('zero', 'one', 'two', 'few', 'many', 'other');

      case 'lv':
        return array('zero', 'one', 'other');

      case 'ga':
      case 'se':
      case 'sma':
      case 'smi':
      case 'smj':
      case 'smn':
      case 'sms':
        return array('one', 'two', 'other');

      case 'ro':
      case 'mo':
      case 'lt':
      case 'cs':
      case 'sk':
      case 'pl':
        return array('one', 'few', 'other');

      case 'hr':
      case 'ru':
      case 'sr':
      case 'uk':
      case 'be':
      case 'bs':
      case 'sh':
      case 'mt':
        return array('one', 'few', 'many', 'other');

      case 'sl':
        return array('one', 'two', 'few', 'other');

      case 'cy':
        return array('one', 'two', 'many', 'other');

      default: // en, de, etc.
        return array('one', 'other');
    }
  }
}