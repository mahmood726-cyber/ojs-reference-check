# Reference Check for OJS

An Open Journal Systems plugin (OJS 3.5.0-4 or later) that lets editors check a submission's
reference list, before publication, for work that has been retracted, flagged or
corrected, and for DOIs that do not resolve.

**Status: in development. Not released and not in the PKP Plugin Gallery.**
Earlier 3.5.0 releases lack the plugin API it uses; on those it shows nothing.

Automated tests passed on 26 November 2026 on OJS 3.5.0-4, 3.5.0-5 and 3.5.0-6
with PHP 8.2, 8.3 and 8.4 and with MariaDB, MySQL and PostgreSQL (not every
combination), using PKP's test journal.

## What an editor sees
Open a submission, go to **Publication > References**, and select **Check
references**. The panel counts the results and lists the references that need a
look, with a link to each notice.

| Result | Meaning |
|---|---|
| Retracted, withdrawn or removed | Crossref holds a retraction, partial retraction, withdrawal or removal, or the title is marked "Retracted" or "Withdrawn" |
| Expression of concern | Crossref holds an expression of concern |
| Correction published | Correction, corrigendum, erratum, addendum or clarification |
| Other update notice | Any other notice, such as a new version |
| DOI not found | The DOI does not resolve; often a typing error |
| A DOI could not be read | It is broken off (for example by a space) or has other text written against it |
| Could not be checked | A lookup failed or ran out of time |
| DOI exists outside Crossref | Registered elsewhere; retraction status was **not** checked |
| No notice found | Crossref held no update notice at the time of the check |
| No DOI | **Not** checked |

## How well it finds retractions
Measured on 26 November 2026 against papers PubMed lists as retracted:

| Sample | Papers | Shown as retracted | Shown as "No notice found" |
|---|---|---|---|
| First sample (used to design the title rule) | 399 | 374 (93.7%) | 16 (4.0%) |
| Second sample, drawn afterwards | 395 | 387 (98.0%) | 8 (2.0%) |

The rest of the first sample showed a correction notice (3) or a DOI outside
Crossref (6). None of 239 other PubMed articles was shown as retracted. In 1,119
reference strings deposited with Crossref that contained their DOI, the plugin
read that DOI exactly in 1,115. These are samples of PubMed-indexed work, so the
figures may not carry over to other fields.

## Limits
- It is an aid, not a verdict. Notices can be late, missing or wrong, and "No
  notice found" is not a certificate that a work is sound.
- References without a DOI are counted but not checked.
- It reads the **saved** reference list. The References field must be enabled
  for the journal.
- It never changes a submission or blocks an editorial step, and it stores no
  results.
- At most 500 DOIs, within about 45 seconds. Anything left over is reported as
  "Could not be checked".
- A DOI with ordinary text written against it, without a space, may be read
  as longer than it is and reported as not found.
- Public Crossref requests are rate limited, so simultaneous checks may fail
  and need repeating.

## Privacy
The plugin sends only what it reads as a DOI in each reference: to the
[Crossref REST API](https://api.crossref.org) and, for DOIs Crossref does not
return, to the [DOI resolver](https://doi.org). Query strings are removed, and a
string containing an e-mail address is not sent. Other text written against a DOI
without a space can still be taken as part of it. The rest of the reference and
the manuscript are not sent. Those services see your server's IP address.
Retraction data includes Retraction Watch data distributed by Crossref.

## Who can run it
Site administrators, journal managers, section editors and assistants, limited
to submissions they can already access. Authors, reviewers and readers cannot.

## Development
```bash
php tests/run.php               # unit tests, no OJS or network needed
node --test tests/ui.test.cjs   # editor panel logic, with stand-ins for OJS
php tests/live.php              # smoke test against Crossref and doi.org
php tests/ojs-compat.php PATH   # against an unpacked OJS release (see the file)
```
The test classes are PHPUnit test cases; `tests/run.php` is a small stand-in for
machines without PHPUnit.

The browser tests in `tests/e2e` upload the release package to OJS with
[PKP's test journal](https://github.com/pkp/datasets) and check the editor panel,
access rules, hostile input, loss of the outside services and disabling. The
GitHub workflow in `.github/workflows/tests.yml` runs them across OJS releases,
PHP versions and databases.

To build a release package:
```bash
git archive --format=tar.gz --prefix=referenceCheck/ -o referenceCheck-1.0.0.0.tar.gz HEAD
```
To install a package by hand, unpack it into `plugins/generic/` and enable
**Reference Check** under **Website > Plugins**.

## AI disclosure
This plugin was developed with generative AI (Anthropic's Claude, through Claude
Code), which wrote the code, tests and documentation under the maintainer's
direction. **Human review of the code is still pending** and is required before
any submission to PKP, in line with the
[PKP policy on AI contributions](https://pkp.sfu.ca/pkp-policy-ai/).

## Licence
GNU General Public License v3.0. See [LICENSE](LICENSE).
