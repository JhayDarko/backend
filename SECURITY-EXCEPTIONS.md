# Security exceptions and findings

## Temporary dependency exception

Detected on 2026-10-22 by `pip-audit` using OSV data.

| Package | Locked version | Advisory | Status | Review deadline |
|---|---:|---|---|---|
| `transformers` | 4.57.6 | `PYSEC-2026-2289` | Temporarily ignored | 2026-11-22 |
| `transformers` | 4.57.6 | `PYSEC-2026-2288` | Temporarily ignored | 2026-11-22 |
| `transformers` | 4.57.6 | `PYSEC-2026-2290` | Temporarily ignored | 2026-11-22 |
| `transformers` | 4.57.6 | `PYSEC-2026-4174` | Temporarily ignored | 2026-11-22 |
| `transformers` | 4.57.6 | `PYSEC-2026-3929` | Temporarily ignored | 2026-11-22 |
| `transformers` | 4.57.6 | `PYSEC-2025-217` | Temporarily ignored | 2026-11-22 |

Trivy also reported these high-severity IDs for `transformers` 4.57.6:

| Advisory | Trivy status | Fixed version reported by Trivy | Review deadline |
|---|---|---:|---|
| `CVE-2026-4372` | Fixed | 5.3.0 | 2026-11-22 |
| `CVE-2026-5241` | Fixed | 5.5.0 | 2026-11-22 |
| `CVE-2026-80047` | Affected; no fix listed | Not listed | 2026-11-22 |
| `CVE-2026-9856` | Fixed | 5.10.0 | 2026-11-22 |

The six `PYSEC` identifiers are ignored by `pip-audit` for the ML runtime and test locks
in `ml_service/requirements.txt` and `ml_service/requirements-test.txt`. The `.trivyignore`
file contains the six `PYSEC` and four `CVE` identifiers listed above. No patched
release within the current major version was identified in the scan. The fixes reported
by Trivy are in major version 5. Moving to a newer major version could affect model
loading and classification behavior, which are outside this infrastructure-only phase.
This does not establish that the vulnerable code paths are unreachable. Reassess
available patched releases and compatibility before the review deadline; remove the
ignores once the package is upgraded or the exception is rejected.
The OSV audit reported 11 ignored entries due to advisory aliases; the six identifiers
listed above are the exceptions applied to the ML locks.

The OCR runtime and test locks had no findings in the OSV audit. The frontend audit
completed with zero vulnerabilities at the configured high threshold after its
dependency overrides were applied. `composer audit` found no advisories.

## Historical credential findings

A redacted Gitleaks full-history scan on 2026-10-22 found credential-formatted values
for `BRAVE_API_KEY` and `GOOGLE_CLOUD_VISION_API_KEY` in historical revisions of
`.env.example` (commits `3da92c2a2dd88470f8b0583999cca10e6eb09ebd` and
`aeed88873b2921dbcaa7835d7a98036490ebb99b`, respectively). The values were not copied
to this report. The current `.env.example` has an empty `BRAVE_API_KEY` and no
`GOOGLE_CLOUD_VISION_API_KEY`; whether the historical credentials are still valid is
not determined.

Treat both credentials as exposed and revoke or rotate them before reuse. They remain
recoverable from Git history; no history rewrite was performed. Rewriting shared
history and force-pushing require explicit coordination and are not part of this
automated CI change. The CI Gitleaks command scans the current checkout without Git
history, so it does not replace the separate remediation of these historical
findings. No allowlist was added for either finding.
