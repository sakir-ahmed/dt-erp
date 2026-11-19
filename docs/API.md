# API conventions

Version ERP endpoints under `/api/v1`. Reuse the same authorization rules, Form Requests where the input contract matches, and business Actions as the web application.

Return HTTP `422` for invalid input, with a stable `VALIDATION_FAILED` code and field-level errors in `details`. Document the response format and errors alongside each endpoint.

Require an `Idempotency-Key` header for writes that clients must be able to retry safely. Document which operations require it and how retries are handled. Ensure retries cannot apply the same business operation twice, including concurrent requests.

Endpoint contracts will be added as ERP workflows are defined.
