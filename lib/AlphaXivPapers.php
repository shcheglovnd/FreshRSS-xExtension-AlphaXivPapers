<?php

declare(strict_types=1);

/**
 * alphaXiv’s list of papers by likes (https://www.alphaxiv.org/?sort=Likes) as an RSS document of the papers with at least
 * a given number of likes.
 *
 * alphaXiv lists the papers first published in a period, the last 3, 7, 30 or 90 days or all time, most liked first, and
 * papers keep gaining likes for weeks. So they are read from the API that alphaXiv’s page uses, by pages of 100 down to the
 * minimum: one request mostly gives all the papers over the threshold, as soon as they reach it.
 *
 * A feed’s URL may set its own period, minimum of likes and likes in the titles, e.g.
 * https://www.alphaxiv.org/?sort=Likes&interval=30+Days&min_likes=200&title_likes=1.
 *
 * Independent of FreshRSS, so it can be tested without it.
 */
final class AlphaXivPapers {
	/** The feed URL with the extension’s settings */
	public const PAGE = 'https://www.alphaxiv.org/?sort=Likes';
	private const SITE = 'https://www.alphaxiv.org';
	private const API = 'https://api.alphaxiv.org/papers/v3/feed';
	private const CARD_PREVIEWS = 'https://thumbnails.assets.alphaxiv.org/card-previews/';
	/** Where the pictures of papers’ first pages are, which alphaXiv shows on a card without a preview */
	private const FIRST_PAGES = 'https://thumbnails.assets.alphaxiv.org/';
	public const MIN_LIKES_DEFAULT = 100;
	public const MIN_LIKES_MAX = 100000;
	/** alphaXiv’s periods, the only ones its API accepts: the papers first published in the last days, or ever */
	public const INTERVALS = ['3 Days', '7 Days', '30 Days', '90 Days', 'All time'];
	public const INTERVAL_DEFAULT = '90 Days';
	/** Papers per API request */
	public const PAGE_SIZE = 100;
	/** Most API requests per refresh, so at most the 1000 most liked papers */
	public const MAX_PAGES = 10;
	/** Most authors listed: FreshRSS keeps 1 KiB of them */
	private const MAX_AUTHORS = 20;
	private const MAX_ORGANIZATIONS = 5;
	private const IMAGE_TYPES = ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'];

	/**
	 * The feed URL for alphaXiv’s list by likes in any form (alphaxiv.org, http://, /explore, other query parameters), else
	 * null: https://www.alphaxiv.org/?sort=Likes, with the feed’s own settings if any ({@see urlSettings()}).
	 * The home page without a `sort` counts too, but not its other orders, such as Hot.
	 */
	public static function pageUrl(string $url): ?string {
		if (preg_match('#^(?:https?://)?(?:www\.)?alphaxiv\.org(?:/|/explore/?)?(?:[?\#].*)?$#i', trim($url)) !== 1) {
			return null;
		}
		$sort = self::params($url)['sort'] ?? '';
		if (!is_string($sort) || ($sort !== '' && strcasecmp($sort, 'Likes') !== 0)) {
			return null;
		}
		$settings = array_filter(self::urlSettings($url), static fn(string|int|bool|null $value): bool => $value !== null);
		return self::PAGE . ($settings !== [] ? '&' . http_build_query($settings) : '');
	}

	/**
	 * The settings of a feed URL, or null when it does not give them in a known form: `interval`, alphaXiv’s own parameter
	 * for the period ({@see interval()}), `min_likes` as a number within its bounds, and `title_likes`, whether titles start
	 * with the likes, as 1 or 0 (or true/false, yes/no, on/off)
	 * @return array{interval:string|null,min_likes:int|null,title_likes:bool|null}
	 */
	public static function urlSettings(string $url): array {
		$params = self::params($url);
		$interval = $params['interval'] ?? null;
		$minLikes = $params['min_likes'] ?? null;
		$titleLikes = $params['title_likes'] ?? null;
		return [
			'interval' => is_string($interval) ? self::interval($interval) : null,
			'min_likes' => is_string($minLikes) && ctype_digit($minLikes) ? self::boundMinLikes((int)$minLikes) : null,
			'title_likes' => is_string($titleLikes) ? match (strtolower($titleLikes)) {
				'1', 'true', 'yes', 'on' => true,
				'0', 'false', 'no', 'off' => false,
				default => null,
			} : null,
		];
	}

	/**
	 * One of alphaXiv’s periods ({@see INTERVALS}), from its name in any case (`90 Days`, `All time`), a number of days
	 * (`90`, `90d`) or `all`, else null
	 */
	public static function interval(string $value): ?string {
		$value = strtolower(trim(preg_replace('/[\s_-]+/', ' ', $value) ?? ''));
		if (in_array($value, ['all', 'all time', 'alltime'], true)) {
			return 'All time';
		}
		if (preg_match('/^(\d{1,4}) ?(?:d|days?)?$/', $value, $matches) === 1) {
			$interval = (int)$matches[1] . ' Days';
			return in_array($interval, self::INTERVALS, true) ? $interval : null;
		}
		return null;
	}

	public static function boundMinLikes(int $minLikes): int {
		return max(0, min(self::MIN_LIKES_MAX, $minLikes));
	}

	/**
	 * The API URL of a period’s papers, most liked first, from page 0, as alphaXiv’s page asks for them
	 * @return non-empty-string
	 */
	public static function apiUrl(string $interval, int $page): string {
		return self::API . '?' . http_build_query(['pageNum' => $page, 'sort' => 'Likes', 'pageSize' => self::PAGE_SIZE,
			'interval' => $interval, 'linkBlogs' => 'true', 'topics' => '[]']);
	}

	/**
	 * @return list<array<string,mixed>>|null the papers of an API answer, null when it is not a list of papers
	 */
	public static function decode(string $json): ?array {
		$answer = json_decode($json, true);
		$items = is_array($answer) ? ($answer['papers'] ?? null) : null;
		if (!is_array($items) || !array_is_list($items)) {
			return null;
		}
		$papers = array_values(array_filter($items, static fn(mixed $item): bool => is_array($item) && is_string($item['universal_paper_id'] ?? null)));
		/** @var list<array<string,mixed>> $papers */
		return $items === [] || $papers !== [] ? $papers : null;
	}

	/**
	 * Whether the next API page may have more papers with enough likes: this page was full, down to its last paper.
	 * @param list<array<string,mixed>> $items
	 */
	public static function hasMore(array $items, int $minLikes): bool {
		return count($items) === self::PAGE_SIZE && self::likes($items[self::PAGE_SIZE - 1]) >= $minLikes;
	}

	/**
	 * A paper of an API answer, whatever its likes, or null if it lacks an ID or a title. Its date is when it was first
	 * published, 0 if unknown.
	 * @param array<string,mixed> $item
	 * @return array{id:string,link:string,title:string,authors:list<string>,html:string,likes:int,date:int}|null
	 */
	public static function paper(array $item): ?array {
		$id = self::text($item['universal_paper_id'] ?? null);
		$title = self::text($item['title'] ?? null);
		if (preg_match('/^[\w.-]+$/', $id) !== 1 || $title === '') {
			return null;
		}
		$likes = self::likes($item);

		$authors = [];
		foreach (is_array($item['authors'] ?? null) ? $item['authors'] : [] as $author) {
			$name = self::text($author);
			if ($name !== '') {
				$authors[] = $name;
			}
		}
		if (count($authors) > self::MAX_AUTHORS) {
			$authors = [...array_slice($authors, 0, self::MAX_AUTHORS), 'et al.'];
		}

		$facts = ['<strong>' . $likes . ' ' . ($likes === 1 ? 'like' : 'likes') . '</strong>'];
		$organizations = [];
		foreach (is_array($item['organization_info'] ?? null) ? $item['organization_info'] : [] as $organization) {
			$name = is_array($organization) ? self::text($organization['name'] ?? null) : '';
			if ($name !== '' && !in_array($name, $organizations, true)) {
				$organizations[] = $name;
			}
		}
		if ($organizations !== []) {
			$facts[] = self::escape(implode(', ', array_slice($organizations, 0, self::MAX_ORGANIZATIONS))
				. (count($organizations) > self::MAX_ORGANIZATIONS ? ', …' : ''));
		}
		$published = self::text($item['first_publication_date'] ?? null);
		$date = strtotime($published !== '' ? $published : self::text($item['publication_date'] ?? null));
		if ($date !== false) {
			$facts[] = gmdate('j M Y', $date);
		}

		$links = [];
		if (preg_match('/^\d{4}\.\d{4,5}$/', $id) === 1) {
			$links[] = self::link('https://arxiv.org/abs/' . $id, 'arXiv');
			$links[] = self::link('https://arxiv.org/pdf/' . $id, 'PDF');
		}
		$github = self::text($item['github_url'] ?? null);
		if (str_starts_with($github, 'https://')) {
			$stars = $item['github_stars'] ?? null;
			$links[] = self::link($github, 'GitHub') . (is_int($stars) ? ' (' . $stars . ' ★)' : '');
		}
		foreach (['external_blog', 'external_link'] as $key) {
			$source = is_array($item[$key] ?? null) ? $item[$key] : [];
			$url = self::text($source['source_url'] ?? null);
			if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
				$name = self::text($source['source_name'] ?? null);
				$links[] = self::link($url, 'Source') . ($name !== '' ? ' (' . self::escape($name) . ')' : '');
				break;
			}
		}

		$abstract = trim(self::text($item['abstract'] ?? null, collapse: false));
		$html = '<p>' . implode(' · ', $facts) . "</p>\n"
			. self::image($item)
			. ($abstract !== '' ? '<p>' . nl2br(self::escape($abstract), false) . "</p>\n" : '')
			. ($links !== [] ? '<p>' . implode(' · ', $links) . "</p>\n" : '');
		return ['id' => $id, 'link' => self::SITE . '/abs/' . $id, 'title' => $title, 'authors' => $authors, 'html' => $html,
			'likes' => $likes, 'date' => $date !== false ? $date : 0];
	}

	/**
	 * The papers with at least $minLikes, most liked first, each once.
	 * @param list<array{id:string,link:string,title:string,authors:list<string>,html:string,likes:int,date:int}> $papers
	 * @return list<array{id:string,link:string,title:string,authors:list<string>,html:string,likes:int,date:int}>
	 */
	public static function papers(array $papers, int $minLikes): array {
		$over = [];
		foreach ($papers as $paper) {
			if ($paper['likes'] >= $minLikes) {
				$over[$paper['id']] ??= $paper;
			}
		}
		$over = array_values($over);
		usort($over, static fn(array $a, array $b): int => $b['likes'] <=> $a['likes']);
		return $over;
	}

	/**
	 * The entries of a feed to delete as below its minimum, e.g. after it was raised: those whose likes, as stored in the
	 * entry, are below it, unless the API now gives at least the minimum. An entry keeps the likes it had when it was last
	 * listed, so a paper that loses a few likes and falls under the minimum stays, rather than coming and going. An entry
	 * without stored likes stays too.
	 * @param list<array{id:string,guid:string,html:string}> $entries
	 * @param list<array{id:string,link:string,title:string,authors:list<string>,html:string,likes:int,date:int}> $papers
	 *  the papers of this refresh, whatever their likes
	 * @return list<string> entry IDs
	 */
	public static function below(array $entries, array $papers, int $minLikes): array {
		$likes = [];
		foreach ($papers as $paper) {
			$likes[$paper['link']] = max($likes[$paper['link']] ?? 0, $paper['likes']);
		}
		$ids = [];
		foreach ($entries as $entry) {
			if (preg_match('#<strong>(\d+) likes?</strong>#', $entry['html'], $matches) === 1 && (int)$matches[1] < $minLikes
				&& ($likes[$entry['guid']] ?? 0) < $minLikes) {
				$ids[] = $entry['id'];
			}
		}
		return $ids;
	}

	/**
	 * The RSS document of the papers. Each is dated with the time of the refresh that first lists it, i.e. when it reached
	 * the threshold, so that a paper reaching it weeks after it appeared comes first in a reader sorted by date. On the
	 * feed’s first refresh, $backdate dates them when they were first published instead, rather than all at once.
	 * Papers dated together are one second apart, the most liked first.
	 * With $titleLikes, titles start with the likes, e.g. “▲1027 · Kimi K3: …”.
	 * @param list<array{id:string,link:string,title:string,authors:list<string>,html:string,likes:int,date:int}> $papers
	 */
	public static function rss(array $papers, int $minLikes, string $interval, int $now, bool $backdate = false, bool $titleLikes = false): string {
		$period = $interval === 'All time' ? 'all time' : 'last ' . strtolower($interval);
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
			. "<channel>\n"
			. self::element('title', 'alphaXiv papers (' . $minLikes . '+ likes, ' . $period . ')')
			. self::element('link', self::SITE . '/?' . http_build_query(['sort' => 'Likes', 'interval' => $interval]))
			. self::element('description', 'Papers on alphaXiv with at least ' . $minLikes . ' likes'
				. ($interval === 'All time' ? '' : ', first published in the ' . $period));
		foreach ($papers as $rank => $paper) {
			$xml .= "<item>\n"
				. self::element('title', ($titleLikes ? '▲' . $paper['likes'] . ' · ' : '') . $paper['title'])
				. self::element('link', $paper['link'])
				. self::element('guid', $paper['link'])
				. self::element('pubDate', gmdate(DATE_RSS, $backdate && $paper['date'] > 0 ? min($now - $rank, $paper['date']) : $now - $rank));
			foreach ($paper['authors'] as $author) {
				$xml .= self::element('dc:creator', $author);
			}
			$xml .= '<description><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', self::xmlChars($paper['html'])) . "]]></description>\n"
				. "</item>\n";
		}
		return $xml . "</channel>\n</rss>\n";
	}

	/**
	 * The figure that alphaXiv picked for the paper’s card, or the cover of a blog post. Not the picture of the paper’s first
	 * page that alphaXiv shows otherwise, which repeats the title and the abstract.
	 * @param array<string,mixed> $item
	 */
	private static function image(array $item): string {
		$preview = self::text($item['card_preview_blob_id'] ?? null);
		if (preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $preview) === 1) {
			$url = self::CARD_PREVIEWS . strtolower($preview) . '.webp';
		} else {
			$url = self::text($item['image_url'] ?? null);
			$path = parse_url($url, PHP_URL_PATH);
			if (!str_starts_with($url, 'https://') || str_starts_with($url, self::FIRST_PAGES)
				|| !in_array(strtolower(pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION)), self::IMAGE_TYPES, true)) {
				return '';
			}
		}
		return '<p><img src="' . self::escape($url) . '" alt="" /></p>' . "\n";
	}

	/** @param array<string,mixed> $item */
	private static function likes(array $item): int {
		$likes = is_array($item['metrics'] ?? null) ? ($item['metrics']['public_total_votes'] ?? 0) : 0;
		return is_int($likes) ? $likes : 0;
	}

	/** @return array<mixed> the query parameters of a URL */
	private static function params(string $url): array {
		parse_str(preg_match('/\?([^#]*)/', $url, $matches) === 1 ? $matches[1] : '', $params);
		return $params;
	}

	/** A string value, trimmed, with its runs of white space made one space unless $collapse is false */
	private static function text(mixed $value, bool $collapse = true): string {
		if (!is_string($value)) {
			return '';
		}
		return trim($collapse ? (preg_replace('/\s+/u', ' ', $value) ?? $value) : $value);
	}

	private static function link(string $url, string $text): string {
		return '<a href="' . self::escape($url) . '">' . self::escape($text) . '</a>';
	}

	private static function element(string $name, string $value): string {
		return '<' . $name . '>' . self::escape($value) . '</' . $name . ">\n";
	}

	private static function escape(string $text): string {
		return htmlspecialchars(self::xmlChars($text), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** Drops characters XML 1.0 does not allow. */
	private static function xmlChars(string $text): string {
		return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
	}
}
