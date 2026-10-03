<?php

declare(strict_types=1);

/**
 * Manual check against alphaXiv’s API, without FreshRSS: prints the papers the feed would list now.
 *
 *   php tests/preview.php                 # with at least 100 likes, of the last 90 days
 *   php tests/preview.php 200 30          # with at least 200, of the last 30 days
 *   php tests/preview.php 500 all --rss   # the generated RSS instead of a summary
 */

require dirname(__DIR__) . '/lib/AlphaXivPapers.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$minLikes = AlphaXivPapers::boundMinLikes((int)($args[0] ?? AlphaXivPapers::MIN_LIKES_DEFAULT));
$interval = AlphaXivPapers::interval($args[1] ?? AlphaXivPapers::INTERVAL_DEFAULT);
if ($interval === null) {
	fwrite(STDERR, 'the period is one of ' . implode(', ', AlphaXivPapers::INTERVALS) . ", or 3, 7, 30, 90 or all\n");
	exit(1);
}
$context = stream_context_create(['http' => ['header' => "User-Agent: FreshRSS alphaXiv Papers test\r\n", 'timeout' => 60]]);
$now = time();
$papers = [];
$requests = 0;
for ($page = 0; $page < AlphaXivPapers::MAX_PAGES; $page++) {
	$url = AlphaXivPapers::apiUrl($interval, $page);
	$requests++;
	$items = AlphaXivPapers::decode((string)file_get_contents($url, false, $context));
	if ($items === null) {
		fwrite(STDERR, "not a list of papers: $url\n");
		exit(1);
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
$read = count($papers);
$papers = AlphaXivPapers::papers($papers, $minLikes);
if (in_array('--rss', $argv, true)) {
	echo AlphaXivPapers::rss($papers, $minLikes, $interval, $now);
	exit(0);
}
printf("%d papers with at least %d likes, of %d read in %d requests (%s)\n\n", count($papers), $minLikes, $read, $requests, $interval);
foreach ($papers as $rank => $paper) {
	printf("%4d. %5d  %s  %-28s  %s\n", $rank + 1, $paper['likes'], gmdate('Y-m-d', $paper['date']), mb_strimwidth($paper['id'], 0, 28, '…'),
		mb_strimwidth($paper['title'], 0, 70, '…'));
}
