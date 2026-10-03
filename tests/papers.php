<?php

declare(strict_types=1);

/**
 * Checks of {@see AlphaXivPapers} without FreshRSS or network: php tests/papers.php
 */

require dirname(__DIR__) . '/lib/AlphaXivPapers.php';

$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void {
	global $failures;
	if ($actual === $expected) {
		echo "ok   $name\n";
	} else {
		$failures++;
		echo "FAIL $name\n     expected " . var_export($expected, true) . "\n     got      " . var_export($actual, true) . "\n";
	}
}

/** @return array<string,mixed> an API item */
function item(string $id, int $likes, array $fields = []): array {
	return $fields + [
		'universal_paper_id' => $id, 'title' => "Paper $id", 'abstract' => "Abstract of $id.", 'authors' => ['Ada Lovelace'],
		'first_publication_date' => '2026-09-30T08:00:00.000Z', 'publication_date' => '2026-09-30T08:00:00.000Z',
		'metrics' => ['public_total_votes' => $likes, 'total_votes' => intdiv($likes, 2), 'visits_count' => ['all' => 10 * $likes]],
		'organization_info' => [], 'github_url' => null, 'github_stars' => null, 'card_preview_blob_id' => null,
		'image_url' => "https://thumbnails.assets.alphaxiv.org/{$id}v1.png", 'external_blog' => null, 'external_link' => null,
	];
}

/** @return list<array{id:string,link:string,title:string,authors:list<string>,html:string,likes:int,date:int}> */
function read(array $items): array {
	return array_values(array_filter(array_map(AlphaXivPapers::paper(...), $items)));
}

$page = AlphaXivPapers::PAGE;
foreach ([
	'https://www.alphaxiv.org/?sort=Likes', 'https://www.alphaxiv.org/', 'alphaxiv.org', 'http://alphaxiv.org/?sort=likes',
	'https://www.alphaxiv.org/explore?sort=Likes', 'https://www.alphaxiv.org?sort=Likes#top', ' https://www.alphaxiv.org/?sort=Likes&categories=x ',
	'https://www.alphaxiv.org/?sort=',
] as $url) {
	check("pageUrl($url)", AlphaXivPapers::pageUrl($url), $page);
}
foreach ([
	'https://www.alphaxiv.org/?sort=Hot&interval=90+Days', 'https://www.alphaxiv.org/?sort[]=Likes', 'https://www.alphaxiv.org/abs/2607.24653',
	'https://www.alphaxiv.org/explore/papers', 'https://alphaxiv.org.example.com/', 'https://notalphaxiv.org/', 'https://example.com/?sort=Likes',
] as $url) {
	check("pageUrl($url) is not the list by likes", AlphaXivPapers::pageUrl($url), null);
}

foreach ([
	'https://www.alphaxiv.org/?sort=Likes&interval=90+Days' => "$page&interval=90+Days",
	'https://www.alphaxiv.org/?sort=Likes&interval=All%20time' => "$page&interval=All+time",
	'alphaxiv.org/?title_likes=yes&min_likes=0200&interval=30' => "$page&interval=30+Days&min_likes=200&title_likes=1",
	'https://www.alphaxiv.org/?sort=Likes&interval=14+Days' => $page,
	'https://www.alphaxiv.org/?min_likes=0' => "$page&min_likes=0",
	'https://www.alphaxiv.org/?min_likes=99999999999999999999' => "$page&min_likes=100000",
	'https://www.alphaxiv.org/?min_likes=abc&interval=' => $page,
	'https://www.alphaxiv.org/?min_likes=-5&interval[]=7' => $page,
	'https://www.alphaxiv.org/?title_likes=OFF&min_likes=50' => "$page&min_likes=50&title_likes=0",
	'https://www.alphaxiv.org/?title_likes=maybe' => $page,
] as $url => $expected) {
	check("pageUrl($url)", AlphaXivPapers::pageUrl($url), $expected);
}
check('urlSettings: none', AlphaXivPapers::urlSettings($page), ['interval' => null, 'min_likes' => null, 'title_likes' => null]);
check('urlSettings: all', AlphaXivPapers::urlSettings("$page&title_likes=1&min_likes=200&interval=7+Days"),
	['interval' => '7 Days', 'min_likes' => 200, 'title_likes' => true]);
check('urlSettings: no likes in titles', AlphaXivPapers::urlSettings("$page&title_likes=0")['title_likes'], false);

foreach ([
	'90 Days' => '90 Days', '90 days' => '90 Days', ' 7 DAYS ' => '7 Days', '30' => '30 Days', '3d' => '3 Days', '090' => '90 Days',
	'90_days' => '90 Days', 'All time' => 'All time', 'all' => 'All time', 'ALL_TIME' => 'All time', 'all-time' => 'All time',
	'14 Days' => null, '1' => null, '' => null, 'days' => null, 'week' => null,
] as $value => $expected) {
	check("interval($value)", AlphaXivPapers::interval((string)$value), $expected);
}
check('apiUrl', AlphaXivPapers::apiUrl('90 Days', 1),
	'https://api.alphaxiv.org/papers/v3/feed?pageNum=1&sort=Likes&pageSize=100&interval=90+Days&linkBlogs=true&topics=%5B%5D');
check('apiUrl: all time', AlphaXivPapers::apiUrl('All time', 0),
	'https://api.alphaxiv.org/papers/v3/feed?pageNum=0&sort=Likes&pageSize=100&interval=All+time&linkBlogs=true&topics=%5B%5D');

check('decode papers', count(AlphaXivPapers::decode((string)json_encode(['papers' => [item('2609.00001', 3), item('2609.00002', 1)], 'page' => 0])) ?? []), 2);
check('decode a page past the end', AlphaXivPapers::decode('{"papers":[],"page":9}'), []);
check('decode an error', AlphaXivPapers::decode('{"error":{"message":"Request does not match schema."}}'), null);
check('decode papers of something else', AlphaXivPapers::decode('{"papers":[1,2]}'), null);
check('decode a bare list', AlphaXivPapers::decode((string)json_encode([item('2609.00001', 3)])), null);
check('decode HTML', AlphaXivPapers::decode('<html></html>'), null);

$full = array_map(static fn(int $i): array => item(sprintf('2609.%05d', $i), 200 - $i), range(0, 99));
check('hasMore: full page, last one over the threshold', AlphaXivPapers::hasMore($full, 101), true);
check('hasMore: full page, last one under the threshold', AlphaXivPapers::hasMore($full, 102), false);
check('hasMore: last page', AlphaXivPapers::hasMore(array_slice($full, 0, 99), 0), false);

$read = read([
	item('2609.00001', 99),
	item('2609.00002', 100),
	item('2609.00003', 1027, ['github_url' => 'https://github.com/a/b', 'github_stars' => 42, 'card_preview_blob_id' => '01A03EA6-fc4a-7fed-bbf0-075ed7aa10b5',
		'organization_info' => [['name' => 'A & B Lab'], ['name' => 'C Institute'], ['name' => 'A & B Lab']], 'first_publication_date' => '2026-07-27T16:49:54.000Z']),
	item('2609.00002', 100),	// again, as the likes moved papers between pages
	item('../x', 500),
	item('2609.00004', 300, ['title' => "A <b>bold</b>\n  title", 'abstract' => "First line & more\nsecond line ]]> end",
		'authors' => ['', ...array_map(static fn(int $i): string => "Author $i", range(1, 25))], 'first_publication_date' => null,
		'publication_date' => '2026-08-07T06:33:46.000Z', 'organization_info' => array_map(static fn(int $i): array => ['name' => "Org $i"], range(1, 7))]),
	item('2608.blog', 150, ['title' => 'A blog post', 'image_url' => 'https://blog-media.assets.alphaxiv.org/covers/c.png',
		'external_blog' => ['source_name' => 'Qwen', 'source_url' => 'https://qwen.ai/blog?id=x&y=1']]),
	item('2609.navier-stokes', 120, ['metrics' => ['public_total_votes' => '120'], 'image_url' => 'https://example.com/x.svg']),
]);
check('paper: invalid ID dropped', array_column($read, 'id'),
	['2609.00001', '2609.00002', '2609.00003', '2609.00002', '2609.00004', '2608.blog', '2609.navier-stokes']);
$papers = AlphaXivPapers::papers($read, 100);
check('papers: over the threshold, most liked first, once', array_column($papers, 'likes', 'id'),
	['2609.00003' => 1027, '2609.00004' => 300, '2608.blog' => 150, '2609.00002' => 100]);
check('papers: link', $papers[0]['link'], 'https://www.alphaxiv.org/abs/2609.00003');
check('papers: date first published', [$papers[0]['date'], $papers[1]['date']], [gmmktime(16, 49, 54, 7, 27, 2026), gmmktime(6, 33, 46, 8, 7, 2026)]);
check('papers: title as text, white space collapsed', $papers[1]['title'], 'A <b>bold</b> title');
check('papers: authors, at most 20 and et al.', [count($papers[1]['authors']), $papers[1]['authors'][0], end($papers[1]['authors'])], [21, 'Author 1', 'et al.']);
$html = $papers[0]['html'];
check('html: likes, organisations once, date', str_contains($html, '<p><strong>1027 likes</strong> · A &amp; B Lab, C Institute · 27 Jul 2026</p>'), true);
check('html: card preview', str_contains($html, '<img src="https://thumbnails.assets.alphaxiv.org/card-previews/01a03ea6-fc4a-7fed-bbf0-075ed7aa10b5.webp" alt="" />'), true);
check('html: links', str_contains($html, '<a href="https://arxiv.org/abs/2609.00003">arXiv</a> · <a href="https://arxiv.org/pdf/2609.00003">PDF</a> · '
	. '<a href="https://github.com/a/b">GitHub</a> (42 ★)'), true);
check('html: at most 5 organisations', str_contains($papers[1]['html'], '<strong>300 likes</strong> · Org 1, Org 2, Org 3, Org 4, Org 5, … · 7 Aug 2026'), true);
check('html: no picture of the first page', str_contains($papers[1]['html'], '<img'), false);
check('html: abstract escaped, lines kept', str_contains($papers[1]['html'], '<p>First line &amp; more<br>' . "\n" . 'second line ]]&gt; end</p>'), true);
check('html: blog cover and source, no arXiv', [str_contains($papers[2]['html'], '<img src="https://blog-media.assets.alphaxiv.org/covers/c.png" alt="" />'),
	str_contains($papers[2]['html'], '<p><a href="https://qwen.ai/blog?id=x&amp;y=1">Source</a> (Qwen)</p>'), str_contains($papers[2]['html'], 'arxiv.org')], [true, true, false]);
check('html: likes not a number count as 0, no picture of another type', [$read[6]['likes'], str_contains($read[6]['html'], '<img')], [0, false]);
check('html: 1 like', str_contains(read([item('2609.00005', 1)])[0]['html'], '<strong>1 like</strong>'), true);
check('papers: none over the threshold', AlphaXivPapers::papers(read([item('2609.00001', 3)]), 50), []);

$now = gmmktime(12, 0, 0, 10, 2, 2026);
$rss = AlphaXivPapers::rss($papers, 100, '90 Days', $now);
$xml = simplexml_load_string($rss);
check('rss: well-formed', $xml instanceof SimpleXMLElement, true);
if ($xml instanceof SimpleXMLElement) {
	check('rss: items in order', array_map(static fn(SimpleXMLElement $i): string => (string)$i->guid, $xml->xpath('//item') ?: []), [
		'https://www.alphaxiv.org/abs/2609.00003', 'https://www.alphaxiv.org/abs/2609.00004', 'https://www.alphaxiv.org/abs/2608.blog',
		'https://www.alphaxiv.org/abs/2609.00002',
	]);
	check('rss: dated now, one second apart', array_map(static fn(SimpleXMLElement $i): int => (int)strtotime((string)$i->pubDate), $xml->xpath('//item') ?: []),
		[$now, $now - 1, $now - 2, $now - 3]);
	check('rss: title escaped', (string)$xml->channel->item[1]->title, 'A <b>bold</b> title');
	check('rss: description survives ]]>', str_contains((string)$xml->channel->item[1]->description, 'second line ]]&gt; end'), true);
	check('rss: authors', count($xml->channel->item[1]->children('http://purl.org/dc/elements/1.1/')->creator), 21);
	check('rss: threshold and period in the title, link and description', [(string)$xml->channel->title, (string)$xml->channel->link, (string)$xml->channel->description], [
		'alphaXiv papers (100+ likes, last 90 days)', 'https://www.alphaxiv.org/?sort=Likes&interval=90+Days',
		'Papers on alphaXiv with at least 100 likes, first published in the last 90 days',
	]);
}
$xml = simplexml_load_string(AlphaXivPapers::rss($papers, 500, 'All time', $now));
check('rss: all time', $xml instanceof SimpleXMLElement ? [(string)$xml->channel->title, (string)$xml->channel->link, (string)$xml->channel->description] : [], [
	'alphaXiv papers (500+ likes, all time)', 'https://www.alphaxiv.org/?sort=Likes&interval=All+time', 'Papers on alphaXiv with at least 500 likes',
]);
$xml = simplexml_load_string(AlphaXivPapers::rss($papers, 100, '90 Days', $now, backdate: true));
check('rss: first refresh dated when first published', $xml instanceof SimpleXMLElement
	? array_map(static fn(SimpleXMLElement $i): string => gmdate('Y-m-d H:i:s', (int)strtotime((string)$i->pubDate)), $xml->xpath('//item') ?: []) : [],
	['2026-07-27 16:49:54', '2026-08-07 06:33:46', '2026-09-30 08:00:00', '2026-09-30 08:00:00']);
$recent = read([item('2610.00001', 160, ['first_publication_date' => '2026-10-02T11:59:59.000Z']), item('2610.00002', 150, ['first_publication_date' => '2026-10-02T13:00:00.000Z'])]);
$xml = simplexml_load_string(AlphaXivPapers::rss($recent, 100, '7 Days', $now, backdate: true));
check('rss: first refresh, not after now', $xml instanceof SimpleXMLElement
	? array_map(static fn(SimpleXMLElement $i): int => (int)strtotime((string)$i->pubDate), $xml->xpath('//item') ?: []) : [], [$now - 1, $now - 1]);
$xml = simplexml_load_string(AlphaXivPapers::rss($papers, 100, '90 Days', $now, titleLikes: true));
check('rss: likes in the titles', $xml instanceof SimpleXMLElement
	? array_map(static fn(SimpleXMLElement $i): string => (string)$i->title, $xml->xpath('//item') ?: []) : [],
	['▲1027 · Paper 2609.00003', '▲300 · A <b>bold</b> title', '▲150 · A blog post', '▲100 · Paper 2609.00002']);
check('rss: empty', simplexml_load_string(AlphaXivPapers::rss([], 100, '90 Days', $now)) instanceof SimpleXMLElement, true);

$entry = static fn(string $id, string $paper, string $html): array => ['id' => $id, 'guid' => "https://www.alphaxiv.org/abs/$paper", 'html' => $html];
check('below: deleted after the minimum was raised, kept when over it, or without stored likes', AlphaXivPapers::below([
	$entry('1', '2609.00001', '<p><strong>160 likes</strong> · Lab</p>'),	// read now with 160: below
	$entry('2', '2609.00002', '<p><strong>160 likes</strong></p>'),	// read now with 250: kept
	$entry('3', '2609.00003', '<p><strong>250 likes</strong></p>'),	// lost likes since, now 190: kept
	$entry('4', '2509.00004', '<p><strong>170 likes</strong></p>'),	// out of the period: by its stored likes
	$entry('5', '2509.00005', '<p><strong>950 likes</strong></p>'),
	$entry('6', '2509.00006', '<p>No likes here</p>'),
	$entry('7', '2509.00007', '<p><strong>1 like</strong></p>'),
], read([item('2609.00001', 160), item('2609.00002', 250), item('2609.00003', 190)]), 200), ['1', '4', '7']);
check('below: nothing under an unchanged minimum', AlphaXivPapers::below([$entry('1', '2609.00001', '<p><strong>160 likes</strong></p>')],
	read([item('2609.00001', 99)]), 100), []);

echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
