<?php
class Af_Unburn extends Plugin {
	private $host;

	function about() {
		return array(null,
			"Resolves feedburner and similar feed redirector URLs (requires CURL)",
			"fox");
	}

	function flags() {
		return array("needs_curl" => true);
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
	}

	function hook_article_filter($article) {
		$owner_uid = $article["owner_uid"];

		if (!function_exists("curl_init") || ini_get("open_basedir"))
			return $article;

		if ((strpos($article["link"], "feedproxy.google.com") !== false ||
		  		strpos($article["link"], "/~r/") !== false ||
				strpos($article["link"], "feedsportal.com") !== false)) {

				$ch = curl_init($article["link"]);

				curl_setopt($ch, CURLOPT_TIMEOUT, 5);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_HEADER, true);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_NOBODY, true);
				curl_setopt($ch, CURLOPT_USERAGENT, SELF_USER_AGENT);

				if (Config::get(Config::HTTP_PROXY)) {
					curl_setopt($ch, CURLOPT_PROXY, Config::get(Config::HTTP_PROXY));
				}

				@curl_exec($ch);

				$real_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

				curl_close($ch);

				if ($real_url) {
					/* remove the rest of it */

					$query = parse_url($real_url, PHP_URL_QUERY);

					if ($query && strpos($query, "utm_source") !== false) {
						$args = array();
						parse_str($query, $args);

						foreach (array("utm_source", "utm_medium", "utm_campaign") as $param) {
							if (isset($args[$param])) unset($args[$param]);
						}

						$new_query = http_build_query($args);

						if ($new_query != $query) {
							$real_url = str_replace("?$query", "?$new_query", $real_url);
						}
					}

					$real_url = preg_replace("/\?$/", "", $real_url);

					$article["link"] = $real_url;
				}
		}

		return $article;
	}

	function api_version() {
		return 2;
	}

}
