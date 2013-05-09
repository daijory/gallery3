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
class Gallery_UpgradeChecker {
  const CHECK_URL = "http://galleryproject.org/versioncheck/gallery3";
  const AUTO_CHECK_INTERVAL = 604800;  // 7 days in seconds

  /**
   * Return the last version info blob retrieved from the Gallery website or
   * null if no checks have been performed.
   */
  static function version_info() {
    return unserialize(Cache::instance()->get("upgrade_checker_version_info"));
  }

  /**
   * Return true if auto checking is enabled.
   */
  static function auto_check_enabled() {
    return (bool)Module::get_var("gallery", "upgrade_checker_auto_enabled");
  }

  /**
   * Return true if it's time to auto check.
   */
  static function should_auto_check() {
    if (UpgradeChecker::auto_check_enabled() && Random::int(1, 100) == 1) {
      $version_info = UpgradeChecker::version_info();
      return (!$version_info ||
              (time() - $version_info->timestamp) > UpgradeChecker::AUTO_CHECK_INTERVAL);
    }
    return false;
  }

  /**
   * Fetch version info from the Gallery website.
   */
  static function fetch_version_info() {
    $result = new stdClass();

    $response = Request::factory(UpgradeChecker::CHECK_URL)->execute();
    if ($response->status() == 200) {
      $result->status = "success";
      foreach (explode("\n", $response->body()) as $line) {
        if ($line) {
          list($key, $val) = explode("=", $line, 2);
          $result->data[$key] = $val;
        }
      }
    } else {
      $result->status = "error";
    }

    $result->timestamp = time();
    Cache::instance()->set("upgrade_checker_version_info", serialize($result),
                           86400 * 365, array("upgrade"));
  }

  /**
   * Check the latest version info blob to see if it's time for an upgrade.
   */
  static function get_upgrade_message() {
    $version_info = UpgradeChecker::version_info();
    if ($version_info) {
      if (Gallery::RELEASE_CHANNEL == "release") {
        if (version_compare($version_info->data["release_version"], Gallery::VERSION, ">")) {
          return t("A newer version of Gallery is available! <a href=\"%upgrade-url\">Upgrade now</a> to version %version",
                   array("version" => $version_info->data["release_version"],
                         "upgrade-url" => $version_info->data["release_upgrade_url"]));
        }
      } else {
        $branch = Gallery::RELEASE_BRANCH;
        if (isset($version_info->data["branch_{$branch}_build_number"]) &&
            version_compare($version_info->data["branch_{$branch}_build_number"],
                            Gallery::build_number(), ">")) {
          return t("A newer version of Gallery is available! <a href=\"%upgrade-url\">Upgrade now</a> to version %version (build %build on branch %branch)",
                   array("version" => $version_info->data["branch_{$branch}_version"],
                         "upgrade-url" => $version_info->data["branch_{$branch}_upgrade_url"],
                         "build" => $version_info->data["branch_{$branch}_build_number"],
                         "branch" => $branch));
        }
      }
    }
  }
}
