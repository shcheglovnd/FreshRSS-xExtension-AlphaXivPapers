<?php

return [
	'alphaxiv_papers' => [
		'intro' => 'Subscribe with “Add a feed” and <code>https://www.alphaxiv.org/?sort=Likes</code>. The feed lists the papers of the period that have enough likes on alphaXiv. A paper arrives when it reaches the minimum, often days or weeks after it was published, and is dated then.',
		'min_likes' => 'Minimum likes',
		'min_likes_help' => 'In the 90 days to 2 October 2026, 181 papers had 100 likes or more, about 2 a day, and 547 had 50. Raising it deletes the papers of a feed that are below it at the next refresh, except starred ones and ones with labels. Lowering it brings back the papers now over it, as new ones.',
		'interval' => 'Period',
		'interval_help' => 'alphaXiv’s periods: the papers first published in the last 3, 7, 30 or 90 days, or all of them. A paper is caught only while it is in the period, and papers keep gaining likes for weeks, so a short period misses the late ones. A refresh takes a request to alphaXiv per 100 papers over the minimum, at most 10, so all time needs a high minimum: 247 papers had 500 likes or more, but over 1,200 had 200.',
		'intervals' => [
			'3_days' => 'Last 3 days',
			'7_days' => 'Last 7 days',
			'30_days' => 'Last 30 days',
			'90_days' => 'Last 90 days',
			'all_time' => 'All time',
		],
		'title_likes' => 'Likes at the start of the titles',
		'title_likes_help' => 'E.g. “▲1027 · Kimi K3: …”. At the next download of a feed, FreshRSS updates the titles of the papers in the period, keeping their dates and read state, and then as the likes change. Older papers keep the title they have.',
		'url_settings' => 'These are the defaults. A feed can have its own in its address, e.g. <code>https://www.alphaxiv.org/?sort=Likes&amp;interval=30+Days&amp;min_likes=200&amp;title_likes=1</code> (<code>interval</code> is <code>3+Days</code>, <code>7+Days</code>, <code>30+Days</code>, <code>90+Days</code> or <code>All+time</code>, and <code>title_likes=0</code> is for none), so you can follow several thresholds. A paper over two of them is in both feeds.',
		'error' => [
			'fetch' => 'alphaXiv Papers: could not load %s (%s)',
			'format' => 'alphaXiv Papers: %s did not answer with a list of papers; alphaXiv may have changed its API',
		],
	],
];
