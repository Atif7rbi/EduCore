# EduCore — D4-UX.8 / Password Reset Verification

Status: verification checkpoint

This note records the code-level conclusions for the password reset implementation and the remaining D4-UX.8 production-verification boundary. It does not authorize deployment, merge, production database mutation, or lifecycle promotion.

## Password reset

The password reset flow uses Laravel's `users` password broker and the existing `password_reset_tokens` table.

Current behavior:

- `/forgot-password` and `/reset-password/{token}` are public application entry routes.
- `/auth/forgot-password` and `/auth/reset-password` are rate-limited POST endpoints.
- reset-link requests normalize email case and surrounding whitespace.
- known and unknown accounts receive the same generic success response to avoid account enumeration.
- mail transport failures are not exposed to the requester; the failure class is logged for operations.
- successful reset requires the configured password policy, changes the password, rotates `remember_token`, deletes existing database sessions for the user, emits Laravel's `PasswordReset` event, and consumes the broker token.
- focused backend tests cover generic responses, email normalization, valid reset, existing-session revocation, token reuse rejection, invalid-token rejection, and weak-password rejection.
- focused frontend tests exist for both forgot-password and reset-password pages.

Production mail delivery remains an environment concern. Do not commit SMTP/API credentials. A production transactional-mail provider should be configured through environment variables, with sender-domain SPF/DKIM/DMARC as applicable.

## Lesson content renderer contract

The current admin lesson authoring UI emits lesson content schema version 1 as:

```json
{
  "blocks": [
    {"type": "text", "value": "..."}
  ]
}
```

The learner `LessonPage` renders that exact v1 text-block contract in order. Its existing focused test renders multiple text blocks, and another test verifies that unsupported payloads fall back to a user-facing message rather than exposing or inventing a representation.

Conclusion for D4-UX.8: the current UI authoring path and learner renderer are aligned. No new block types should be invented in this checkpoint. Supporting additional schema versions remains a separate content-schema/product decision.

## D4-CONTENT-001 — learner visibility

The learner read contract intentionally requires the parent `CurriculumVersion` to be `published` before a lesson is visible. This is consistent across curriculum discovery, curriculum-version reads, lesson lists, direct lesson reads, and practice visibility.

Existing `ReadApiTest` explicitly verifies that a lesson whose own status is `published` is still hidden when its parent curriculum version remains `draft`.

Therefore D4-CONTENT-001 is not a learner API defect and must not be fixed by weakening the read filters. If production data contains a published lesson under a draft curriculum version, that content is correctly hidden from learners until the curriculum version is promoted through the authoritative curriculum lifecycle after readiness is confirmed.

## Responsive D4-UX.8 code review

The current admin workspace stylesheet has explicit breakpoints at 70rem, 56rem, and 40rem. It covers:

- desktop-to-tablet sidebar contraction;
- single-column shell below 56rem;
- horizontally scrollable primary navigation and authoring tabs;
- stacked lesson inspector and skills layouts;
- wrapping context metadata;
- single-column authoring forms;
- stacked list/placement/revision headings;
- full-width or wrapped actions;
- mobile-safe form controls;
- single-column inspector summary;
- mobile lesson toolbar/search/filter layout;
- horizontally scrollable lesson table rather than destructive column hiding.

The password-reset pages reuse the responsive login surface and controls.

No additional responsive code change is justified by the static code review alone. Final D4-UX.8 closure still requires visual production/browser review on representative desktop, tablet, and mobile widths after the branch passes regression tests.

## Verification boundary

Before any merge or lifecycle/data promotion:

1. Pull the exact branch HEAD on the server.
2. Run TypeScript checking, the serial frontend regression suite, and production build in the production checkout. These do not mutate the database.
3. Run `PasswordResetFlowTest` only in the isolated test checkout against the dedicated test database; never run PHPUnit in the production checkout.
4. Verify transactional mail with a production-suitable provider configuration without committing credentials.
5. Visually verify login, forgot-password, reset-password, and admin-authoring responsive layouts.
6. Separately decide when the production curriculum version is ready to move from `draft` to `published`; do not bypass the lifecycle by direct SQL.

No merge or production lifecycle promotion is authorized by this note.
