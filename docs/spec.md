# Reference Check — specification (v1)

OJS generic plugin for 3.5.0-4 and later. Product name: `referenceCheck`. Licence: GPL-3.0.

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
| Which registry holds a DOI Crossref did not return? Does it exist? | doi.org registration agency service (many at once) |
| The same, for a DOI containing a comma | doi.org Handle API |
| Is the work marked retracted in its title? | `title` in the Crossref record |

Only the strings read as DOIs leave the server. Query strings are cut off, and
strings holding an e-mail address, "." or ".." path segments, or more than 512
characters are not sent.

## Per-reference result
`retracted`, `concern`, `corrected`, `notice`, `doi_not_found`,
`unreadable_doi`, `check_failed`, `not_in_crossref`, `no_notice`, `no_doi` (listed from most to least urgent).

A reference with several DOIs takes the most urgent result.

## Rules that must hold
1. **Fail closed.** A lookup that errors, times out or returns an unexpected
   body is `check_failed`, never `no_notice`.
2. `no_notice` means only "Crossref held no update notice at the time of the
   check". The interface must say so.
3. `not_in_crossref` means retraction status was **not** checked.
4. Unknown notice types are shown as `notice`, never ignored.
5. A broken-off DOI is `unreadable_doi`, not `no_doi`.
6. Work on author-written text takes time in proportion to its length.
7. Filtered Crossref queries are spaced at least a second apart.

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
