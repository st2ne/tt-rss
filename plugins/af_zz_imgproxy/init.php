<?php
class Af_Zz_ImgProxy extends Plugin {
	private $host;

	function about() {
		return array(1.0,
			"Load insecure images via built-in proxy",
			"fox");
	}

	function is_public_method($method) {
		return $method === "imgproxy";
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_API, $this);
		$host->add_hook($host::HOOK_ENCLOSURE_ENTRY, $this);

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function hook_enclosure_entry($enc) {
		if (preg_match("/image/", $enc["type"]) || preg_match("/\.(jpg|png|gif|bmp)/i", $enc["filename"])) {
			$proxy_all = $this->host->get($this, "proxy_all");

			$enc["content_url"] = $this->rewrite_url_if_needed($enc["content_url"], $proxy_all);
		}

		return $enc;
	}

	function hook_render_article($article) {
		return $this->hook_render_article_cdm($article);
	}

	function hook_render_article_api($headline) {
		return $this->hook_render_article_cdm($headline["headline"], true);
	}

	public function imgproxy() {

		$url = rewrite_relative_url(SELF_URL_PATH, $_REQUEST["url"]);
		$kind = (int) $_REQUEST["kind"]; // 1 = video

		// called without user context, let's just redirect to original URL
		if (!$_SESSION["uid"]) {
			header("Location: $url");
			return;
		}

		$extension = $kind == 1 ? '.mp4' : '.png';
		$local_filename = CACHE_DIR . "/images/" . sha1($url) . $extension;

		if ($_REQUEST["debug"] == "1") { print $url . "\n" . $local_filename; die; }

		header("Content-Disposition: inline; filename=\"".basename($local_filename)."\"");

		if (file_exists($local_filename)) {
			$mimetype = mime_content_type($local_filename);
			header("Content-type: $mimetype");

			$stamp = gmdate("D, d M Y H:i:s", filemtime($local_filename)). " GMT";
			header("Last-Modified: $stamp", true);

			readfile($local_filename);
		} else {
			$data = fetch_file_contents(array("url" => $url));

			if ($data) {
				if (file_put_contents($local_filename, $data)) {
					$mimetype = mime_content_type($local_filename);
					header("Content-type: $mimetype");
				}

				print $data;
			}
		}
	}

	function rewrite_url_if_needed($url, $kind, $all_remote = false) {
		$scheme = parse_url($url, PHP_URL_SCHEME);

		if ($all_remote) {
			$host = parse_url($url, PHP_URL_HOST);
			$self_host = parse_url(SELF_URL_PATH, PHP_URL_HOST);

			$is_remote = $host != $self_host;
		} else {
			$is_remote = false;
		}

		if (($scheme != 'https' && $scheme != "") || $is_remote) {
			if (strpos($url, "data:") !== 0) {
				$url = "public.php?op=pluginhandler&plugin=af_zz_imgproxy&pmethod=imgproxy&kind=$kind&url=" .
					urlencode($url);
			}
		}

		return $url;
	}

	function hook_render_article_cdm($article, $api_mode = false) {

		$need_saving = false;
		$proxy_all = $this->host->get($this, "proxy_all");

		$doc = new DOMDocument();
		if (@$doc->loadHTML($article["content"])) {
			$xpath = new DOMXPath($doc);
			$imgs = $xpath->query("//img[@src]");

			foreach ($imgs as $img) {
				$new_src = $this->rewrite_url_if_needed($img->getAttribute("src"), 0, $proxy_all);

				if ($new_src != $img->getAttribute("src")) {
					$img->setAttribute("src", $new_src);

					$need_saving = true;
				}
			}

			$vids = $xpath->query("//video");

			foreach ($vids as $vid) {
				if ($vid->hasAttribute("poster")) {
					$new_src = $this->rewrite_url_if_needed($vid->getAttribute("poster"), 0, $proxy_all);

					if ($new_src != $vid->getAttribute("poster")) {
						$vid->setAttribute("poster", $new_src);

						$need_saving = true;
					}
				}

				$vsrcs = $xpath->query("source", $vid);

				foreach ($vsrcs as $vsrc) {
					$new_src = $this->rewrite_url_if_needed($vsrc->getAttribute("src"), 1, $proxy_all);

					if ($new_src != $vsrc->getAttribute("src")) {
						$vid->setAttribute("src", $new_src);

						$need_saving = true;
					}
				}
			}
		}

		if ($need_saving) $article["content"] = $doc->saveXML();

		return $article;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__('Image proxy settings (af_zz_imgproxy)')."\">";

		print "<form dojoType=\"dijit.form.Form\">";

		print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
			evt.preventDefault();
			if (this.validate()) {
				console.log(dojo.objectToQuery(this.getValues()));
				new Ajax.Request('backend.php', {
					parameters: dojo.objectToQuery(this.getValues()),
					onComplete: function(transport) {
						notify_info(transport.responseText);
					}
				});
				//this.reset();
			}
			</script>";

		print_hidden("op", "pluginhandler");
		print_hidden("method", "save");
		print_hidden("plugin", "af_zz_imgproxy");

		$proxy_all = $this->host->get($this, "proxy_all");
		print_checkbox("proxy_all", $proxy_all);

		print "&nbsp;<label for=\"proxy_all\">" . __("Enable proxy for all remote images.") . "</label>";

		print "<p>"; print_button("submit", __("Save"));

		print "</form>";

		print "</div>";
	}

	function save() {
		$proxy_all = checkbox_to_sql_bool($_POST["proxy_all"]) == "true";

		$this->host->set($this, "proxy_all", $proxy_all);

		echo __("Configuration saved");
	}

	function api_version() {
		return 2;
	}
}