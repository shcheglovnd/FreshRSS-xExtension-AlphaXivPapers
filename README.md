# alphaXiv Papers — FreshRSS extension

Follow the papers on [alphaXiv](https://www.alphaxiv.org) in [FreshRSS](https://freshrss.org), keeping only
those with enough likes: 100 or more, among the papers of the last 90 days, by default.

## Why

* alphaXiv has no RSS feed, and its pages are built by JavaScript, so FreshRSS can’t read them, not even with
  its HTML + XPath scraping.
* Papers keep gaining likes for weeks. On 2 October 2026, 183 papers of the last 90 days had 100 likes or more;
  138 of them were over a month old, and only 3 were from the last week.

So a feed of the papers over a threshold has to watch the whole period, as alphaXiv’s list by likes does, and
catch each paper when it gets there.

## What it does

* **Subscribe to `https://www.alphaxiv.org/?sort=Likes`.** The feed lists the papers of the period that have at
  least the minimum of likes. These are settings, and a feed can have its own (see below).
* **Papers arrive when they reach the threshold**, and are dated then, so they come first in a reader sorted by
  date. On the feed’s first refresh, the papers already over the threshold are dated when they were first
  published instead, rather than all at once.
* **Each paper shows** its likes, organisations and date of first publication; the figure alphaXiv picked for
  its card, or the cover of a blog post; the abstract; and links to arXiv, the PDF, the GitHub repository (with
  its stars) and the original of a blog post. The entry links to the paper on alphaXiv. The authors are the
  entry’s authors, the first 20 of them.
* **Likes stay current** while the paper is in the period. FreshRSS updates the entry, which keeps its first
  date and its read state.

The papers come from the API that alphaXiv’s page uses, which lists a period’s papers by likes, most first:
`https://api.alphaxiv.org/papers/v3/feed?sort=Likes&interval=90+Days&pageSize=100&pageNum=0`. A refresh takes
one request per 100 papers over the minimum, so 2 by default, and at most 10. An answer is 2 to 4 MB of JSON,
mostly about the authors. FreshRSS keeps the answers for 13 minutes, so feeds refreshed together share them.
The page itself is never read.

## Settings

Under *Settings → Extensions → alphaXiv Papers*:

* **Minimum likes**, 100 by default. In the 90 days to 2 October 2026, 181 papers had 100 likes or more, about
  2 a day, and 547 had 50. When you raise it, the next refresh deletes the papers of the feed that are below
  it, except starred ones and ones with labels. When you lower it, the papers now over it arrive together, as
  new ones.
* **Period**, the last 90 days by default. These are alphaXiv’s periods: the papers first published in the last
  3, 7, 30 or 90 days, or all of them. A paper is caught only while it is in the period, so a short period
  misses the papers that reach the minimum later: the last 7 days had only 2 papers with 100 likes. All time
  needs a high minimum, as a feed lists at most the 1000 most liked papers: 247 papers had 500 likes or more,
  but over 1,200 had 200.
* **Likes at the start of the titles**, off by default: “▲1027 · Kimi K3: …”. At a feed’s next download,
  FreshRSS updates the titles of the papers in the period, keeping their dates and read state, and then as
  their likes change. Older papers keep the title they have, as do all papers when you switch it off.

A refresh applies changed settings at once: the extension drops SimplePie’s cached copy of the feed, which would
otherwise answer refreshes for 13 minutes. Only the API answers may be up to 13 minutes old.

A paper stays in a feed once it is in, even if it later loses a few likes and falls below the minimum, or
leaves the period. Only a higher minimum removes papers, by the likes they had when last listed.

These are the defaults. **A feed can have its own** in its address, so you can follow several thresholds or
periods:

```text
https://www.alphaxiv.org/?sort=Likes&interval=90+Days
https://www.alphaxiv.org/?sort=Likes&interval=30+Days&min_likes=200&title_likes=1
https://www.alphaxiv.org/?sort=Likes&interval=All+time&min_likes=1000
```

`interval` is alphaXiv’s own parameter, so the address of its list by likes, as in the first line, works as it
is, with its period. It is `3+Days`, `7+Days`, `30+Days`, `90+Days` or `All+time` (also `90` or `all`).
`title_likes` takes `1` or `0` (also `true`/`false`, `yes`/`no`, `on`/`off`). *Add a feed* with such an
address. Other parameters are dropped, and out-of-range values are brought within bounds. The home page without
`sort` counts as the list by likes, but other orders, such as `sort=Hot`, are left alone. A paper over two
thresholds is in both feeds, as separate entries. A feed is named after its minimum and period when added, e.g.
“alphaXiv papers (100+ likes, last 90 days)”, and FreshRSS doesn’t rename it later.

## Installation

1. Put this repository in FreshRSS’s `extensions/` folder, e.g. as
   `extensions/xExtension-AlphaXivPapers`:

   ```sh
   cd /path/to/FreshRSS/extensions
   git clone https://github.com/shcheglovnd/FreshRSS-xExtension-AlphaXivPapers.git xExtension-AlphaXivPapers
   ```

2. Enable **alphaXiv Papers** under *Settings → Extensions*.
3. *Add a feed* with `https://www.alphaxiv.org/?sort=Likes`, or an address with its own settings.

Requires FreshRSS 1.28 or newer.

## Development

```sh
php tests/papers.php                 # parsing, filtering and RSS, without FreshRSS or network
php tests/preview.php [100 [90]]     # what the feed would list now, from the API: minimum likes, period
php tests/preview.php 500 all --rss  # the RSS itself
```

## License

[MIT](LICENSE)
