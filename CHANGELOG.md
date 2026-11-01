# Changelog

## 0.1.1 - not released yet

- A signed-in person's error on a page without the `auth` middleware names them. Nothing had loaded the user when
  the error was reported, so it went without anyone, and its issue said "0 customers hit". The id now comes from the
  session, still without a query. The token route (`token_url`) gets it the same way.
- The README's `identifyUsing` and browser config examples start from the id alone. `account` and `internal` are
  for users that have them: a fresh app's `is_staff` is null, and null is no `internal`.
- `mybeast:errors-release`'s description names My Beast Errors, not an old host.

## 0.1.0 - 2026-11-01

The first release.
