# AGENTS.md

## Project context
- This project is built with **Laravel**.
- It integrates with **Amazon APIs** (including SP-API related workflows).

## Integration policy
- Going forward, use **only official Amazon SDKs/libraries already adopted by this project** for Amazon API integrations.
- Do **not** introduce new custom or unofficial Amazon API client strategies.
- Legacy/non-SDK approaches may exist in the codebase from earlier implementations; when touching those areas, prefer migration toward the official SDK-based approach.
- Before adding a new Amazon dependency, confirm it is Amazon-official and aligned with current project architecture.

## Laravel integration architecture (required for new SP-API work)
- Register Amazon clients through the **Laravel Service Container** (service providers + DI), not ad-hoc object creation in controllers.
- Keep Amazon API orchestration in dedicated **service classes** under `App\\Services\\...`; controllers should stay thin.
- Centralize Amazon credentials, regions, endpoints, and marketplace configuration in Laravel config files + environment variables.
- Prefer constructor injection, typed dependencies, and testable service boundaries.

## Engineering standards
- Follow Laravel best practices for application structure, configuration, dependency injection, validation, error handling, and testing.
- Follow Amazon API best practices for authentication, rate-limit handling, retries/backoff, pagination, idempotency, and request/response validation.
- Favor clear, maintainable, and well-documented integration code that aligns with both Laravel and Amazon guidance.

## Change management guidance
- For any Amazon integration refactor, document migration intent and compatibility impact in PR notes.
- Avoid mixing multiple Amazon client strategies in the same new feature; standardize on the official SDK path.
