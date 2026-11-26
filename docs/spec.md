# Reference Check — specification (v1)

OJS 3.5 generic plugin. Product name: `referenceCheck`. Licence: GPL-3.0.

## Goal
Warn editors, before publication, when a submission cites work that has been
retracted, withdrawn, flagged or corrected, or cites a DOI that does not resolve.

## Non-goals (v1)
- No automatic rejection or blocking of any editorial step.
- Nothing shown to readers or authors.
- No stored results and no database changes: each check runs on request.
- No matching of references that carry no DOI (they are counted as "not checked").

## Data sources
| Question | Source |
|---|---|
| Is there a retraction/correction notice? | Crossref REST API `updated-by` (includes Retraction Watch data) |
| Does a DOI unknown to Crossref exist at all? | doi.org Handle API |

Only the DOI strings extracted from references leave the server. No manuscript
text, author names or e-mail addresses are sent.

## Per-reference result
`retracted`, `concern`, `corrected`, `notice`, `doi_not_found`, `check_failed`,
`not_in_crossref`, `no_notice`, `no_doi` (listed from most to least urgent).

A reference with several DOIs takes the most urgent result.

## Rules that must hold
1. **Fail closed.** A lookup that errors, times out or returns an unexpected
   body is `check_failed`, never `no_notice`.
2. `no_notice` means only "Crossref held no update notice at the time of the
   check". The interface must say so.
3. `not_in_crossref` means retraction status was **not** checked.
4. Unknown notice types are shown as `notice`, never ignored.

## Portfolio recon (2026-11-26)
`find-related-repos.py` returned no matches for "retraction check references" or
"DOI verification citation" (manifest generatedAt 2026-04-27, 7 projects
scored, so the index is stale and small). Two on-disk projects found by name,
`retraction-gravity` and `retractripple`, model how retractions propagate; a
search of them found no Crossref client. Everything here is net-new.

## Related work
GetFTR (gallery plugin) shows readers retraction/update flags through its
buttons and needs an integrator ID. `crossrefReferenceLinking` deposits
references with Crossref. Neither gives editors a pre-publication report.
