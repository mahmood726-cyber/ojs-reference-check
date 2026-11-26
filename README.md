# Reference Check for OJS

An Open Journal Systems 3.5 plugin that lets editors check a submission's
reference list, before publication, for work that has been retracted, flagged or
corrected, and for DOIs that do not resolve.

**Status: in development. Not yet tested inside a running OJS site, not
released, and not in the PKP Plugin Gallery.**

## What an editor sees
Open a submission, go to **Publication > References**, and select **Check
references**. The panel counts the results and lists the references that need a
look, with a link to each notice.

| Result | Meaning |
|---|---|
| Retracted, withdrawn or removed | Crossref holds a retraction, partial retraction, withdrawal or removal |
| Expression of concern | Crossref holds an expression of concern |
| Correction published | Correction, corrigendum, erratum, addendum or clarification |
| Other update notice | Any other notice, such as a new version |
| DOI not found | The DOI does not resolve; often a typing error |
| Could not be checked | A lookup failed or ran out of time |
| DOI exists outside Crossref | Registered elsewhere; retraction status was **not** checked |
| No notice found | Crossref held no update notice at the time of the check |
| No DOI | **Not** checked |

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
- Public Crossref requests are rate limited, so simultaneous checks may fail
  and need repeating.

## Privacy
The plugin sends the DOIs found in the references to the
[Crossref REST API](https://api.crossref.org) and, for DOIs Crossref does not
know, to the [DOI resolver](https://doi.org). No reference text, manuscript
text, names or e-mail addresses are sent. Those services see your server's IP
address. Retraction data includes Retraction Watch data distributed by
Crossref.

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
