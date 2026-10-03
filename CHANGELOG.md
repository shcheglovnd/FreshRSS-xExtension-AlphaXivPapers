# Changelog

## 0.1.0 — 2026-10-03

First release.

* `https://www.alphaxiv.org/?sort=Likes` as a feed of the papers on alphaXiv with at least a minimum of likes,
  100 by default, among the papers first published in a period, the last 90 days by default. Read from the API
  that alphaXiv’s page uses, which lists a period’s papers by likes, most first.
* Papers are dated when they reach the threshold; on a feed’s first refresh, when they were first published.
  Updates of their likes keep that date.
* Likes at the start of the titles, e.g. “▲1027 · Kimi K3: …”: a setting, off by default.
* A feed’s own settings in its address, e.g.
  `https://www.alphaxiv.org/?sort=Likes&interval=30+Days&min_likes=200&title_likes=1`, so that several feeds can
  follow different thresholds and periods. `interval` is alphaXiv’s own parameter, so the address of its list by
  likes works as it is. The extension’s settings are the defaults.
* Raising a feed’s minimum deletes its papers below it at the next refresh, except starred ones and ones with
  labels. A paper that loses a few likes once in the feed stays.
* A refresh applies changed settings at once. API answers are cached for 13 minutes, so feeds refreshed together
  share them.
* Works on FreshRSS 1.28 and newer.
