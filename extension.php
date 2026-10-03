<?php

declare(strict_types=1);

/**
 * alphaXiv’s papers as a feed of those with at least a given number of likes, subscribed to as
 * https://www.alphaxiv.org/?sort=Likes.
 *
 * The page is built by JavaScript. The feed is built from the API it uses instead ({@see AlphaXivPapers}), which lists the
 * papers of a period by likes, so that it gets the papers that reach the threshold weeks later too.
 * The period, the minimum of likes and the likes in the titles are settings, which a feed’s URL may override:
 * https://www.alphaxiv.org/?sort=Likes&interval=30+Days&min_likes=200&title_likes=1.
 */
final class AlphaXivPapersExtension extends Minz_Extension {
	/** @var array<int,true> IDs of the feeds read by this extension during this request */
	private array $feedIds = [];

	#[\Override]
	public function init(): void {
		parent::init();
		require_once __DIR__ . '/lib/AlphaXivPapers.php';
		require_once __DIR__ . '/lib/AlphaXivPapersDAO.php';
		$this->registerTranslates();
		// Hook names as strings rather than Minz_HookType, to stay compatible with FreshRSS 1.28
		$this->registerHook('check_url_before_add', [$this, 'checkUrlBeforeAdd']);
		$this->registerHook('simplepie_before_init', [$this, 'simplepieBeforeInit']);
		$this->registerHook('entry_before_update', [$this, 'entryBeforeUpdate']);
	}

	/**
	 * “Add a feed” accepts alphaXiv’s list by likes in any form (alphaxiv.org, http://, /explore, …), which becomes
	 * https://www.alphaxiv.org/?sort=Likes, with its own `interval`, `min_likes` and `title_likes` if given.
	 */
	public function checkUrlBeforeAdd(string $url): string {
		return AlphaXivPapers::pageUrl($url) ?? $url;
	}

	/**
	 * Before SimplePie fetches the feed, give it the RSS built from the API, and delete the feed’s papers that are below its
	 * minimum ({@see deleteBelow()}).
	 * @throws FreshRSS_Feed_Exception when the API cannot be read
	 */
	public function simplepieBeforeInit(FreshRSS_SimplePieCustom $simplePie, FreshRSS_Feed $feed): void {
		if (!in_array($feed->kind(), [FreshRSS_Feed::KIND_RSS, FreshRSS_Feed::KIND_RSS_FORCED], true)) {
			return;
		}
		$url = htmlspecialchars_decode($feed->url(), ENT_QUOTES);
		if (AlphaXivPapers::pageUrl($url) === null) {
			return;
		}
		$settings = AlphaXivPapers::urlSettings($url);
		$interval = $settings['interval'] ?? $this->interval();
		$minLikes = $settings['min_likes'] ?? $this->minLikes();
		// Each page is turned into papers at once: an API answer of 100 papers is about 3 MB of JSON, mostly about the authors
		$papers = [];
		for ($page = 0; $page < AlphaXivPapers::MAX_PAGES; $page++) {
			$apiUrl = AlphaXivPapers::apiUrl($interval, $page);
			$items = AlphaXivPapers::decode(self::fetch($apiUrl, $feed));
			if ($items === null) {
				throw new FreshRSS_Feed_Exception(_t('ext.alphaxiv_papers.error.format', $apiUrl));
			}
			foreach ($items as $item) {
				$paper = AlphaXivPapers::paper($item);
				if ($paper !== null) {
					$papers[] = $paper;
				}
			}
			if (!AlphaXivPapers::hasMore($items, $minLikes)) {
				break;
			}
		}
		self::deleteBelow($feed, $papers, $minLikes);
		$rss = AlphaXivPapers::rss(AlphaXivPapers::papers($papers, $minLikes), $minLikes, $interval, time(),
			backdate: $feed->lastUpdate() <= 0, titleLikes: $settings['title_likes'] ?? $this->titleLikes());
		$this->feedIds[$feed->id()] = true;

		// SimplePie would answer a refresh within its cache duration (800 s) from its cache rather than from this RSS, which
		// reflects the current settings. Drop the cache as FreshRSS’s own option to clear it does, without its favicon rebuild.
		@unlink($feed->cacheFilename());

		// With a Content-Type rather than force_feed(true), which would make SimplePie cache the feed under another name
		// than the one FreshRSS reads the time of the last refresh from
		$response = (new \SimplePie\HTTP\RawTextResponse($rss, $url))->with_header('content-type', 'application/rss+xml; charset=UTF-8');
		$file = \SimplePie\File::fromResponse($response);
		$simplePie->set_file($file);	// takes a reference
	}

	/**
	 * Delete the feed’s papers that are below its minimum, e.g. after it was raised ({@see AlphaXivPapers::below()}),
	 * except starred ones and ones with labels.
	 * @param list<array{id:string,link:string,title:string,authors:list<string>,html:string,likes:int,date:int}> $papers
	 */
	private static function deleteBelow(FreshRSS_Feed $feed, array $papers, int $minLikes): void {
		$entries = [];
		foreach (FreshRSS_Factory::createEntryDao()->listWhere('f', $feed->id(), limit: 0) as $entry) {
			$entries[] = ['id' => $entry->id(), 'guid' => $entry->guid(), 'html' => $entry->content(false)];
		}
		$ids = AlphaXivPapers::below($entries, $papers, $minLikes);
		$deleted = $ids === [] ? 0 : (new AlphaXivPapersDAO())->deleteEntries($feed->id(), $ids);
		if ($deleted > 0) {
			FreshRSS_Factory::createFeedDao()->updateCachedValues($feed->id());
			Minz_Log::notice('alphaXiv Papers: deleted ' . $deleted . ' papers below ' . $minLikes . ' likes from ' . $feed->url(includeCredentials: false));
		}
	}

	/**
	 * An API answer, which FreshRSS caches for its cache duration (800 s): feeds refreshed together share it.
	 * A cache file is also required before FreshRSS 1.30.
	 * @param non-empty-string $url
	 * @throws FreshRSS_Feed_Exception
	 */
	private static function fetch(string $url, FreshRSS_Feed $feed): string {
		$response = FreshRSS_http_Util::httpGet($url, $feed->cacheFilename($url), 'json', $feed->attributes(), $feed->curlOptions());
		if ($response['fail'] || $response['body'] === '') {
			// `status` and `error` are only returned since FreshRSS 1.29
			$status = $response['status'] ?? 0;
			$status = is_int($status) ? abs($status) : 0;
			$error = $response['error'] ?? '';
			throw new FreshRSS_Feed_Exception(_t('ext.alphaxiv_papers.error.fetch', $url,
				$status > 0 ? 'HTTP ' . $status : (is_string($error) && $error !== '' ? $error : '?')), $status);
		}
		return $response['body'];
	}

	/**
	 * A listed paper changes with its likes and GitHub stars, and with the setting of likes in the titles.
	 * FreshRSS then updates it with the date of the refresh, which would move it back to the top; keep its first date,
	 * when it reached the threshold.
	 */
	public function entryBeforeUpdate(FreshRSS_Entry $entry): FreshRSS_Entry {
		if (isset($this->feedIds[$entry->feedId()])) {
			$stored = FreshRSS_Factory::createEntryDao()->searchByGuid($entry->feedId(), $entry->guid());
			if ($stored !== null) {
				$entry->_date($stored->date(true));
			}
		}
		return $entry;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		require_once __DIR__ . '/lib/AlphaXivPapers.php';	// FreshRSS also configures a disabled extension, without init()
		$this->registerTranslates();
		if (Minz_Request::isPost()) {
			$this->setUserConfiguration([
				'min_likes' => AlphaXivPapers::boundMinLikes(Minz_Request::paramInt('min_likes')),
				'interval' => AlphaXivPapers::interval(Minz_Request::paramString('interval')) ?? AlphaXivPapers::INTERVAL_DEFAULT,
				'title_likes' => Minz_Request::paramBoolean('title_likes'),
			]);
		}
	}

	// getUserConfigurationValue() rather than the typed getters, which need FreshRSS 1.29
	/** The minimum of likes of the feeds whose URL does not give one */
	public function minLikes(): int {
		$minLikes = $this->getUserConfigurationValue('min_likes');
		return is_int($minLikes) ? AlphaXivPapers::boundMinLikes($minLikes) : AlphaXivPapers::MIN_LIKES_DEFAULT;
	}

	/** alphaXiv’s period for the feeds whose URL does not give one, e.g. `90 Days` */
	public function interval(): string {
		$interval = $this->getUserConfigurationValue('interval');
		return (is_string($interval) ? AlphaXivPapers::interval($interval) : null) ?? AlphaXivPapers::INTERVAL_DEFAULT;
	}

	/** Whether the titles start with the likes, for the feeds whose URL does not say */
	public function titleLikes(): bool {
		return $this->getUserConfigurationValue('title_likes') === true;
	}
}
