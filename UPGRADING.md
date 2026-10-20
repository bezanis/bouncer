# Updating this fork

This fork incorporates `JosephSilber/bouncer` through `8f8fbecd3abbfa2e922a1b9f1c5ec2de0b3d705f` (2026-03-18), together with the entity-role capabilities from `feature/role-restricted-to-entity` through `fc2564d`.

## Requirements

PHP 8.2+ and Laravel/Eloquent 11–13 are supported, following current upstream. Laravel 13 requires PHP 8.3+. Applications on older versions should stay on their existing package version until they upgrade their framework and PHP.

Prefer current Laravel 12 or 13 releases for deployment. Although Laravel 11 compatibility is tested, Composer reports framework security advisories for its latest available release (11.57.0 at integration time); this package update does not patch Laravel itself.

## Existing databases

Back up your database before upgrading. Do not republish or rerun the table-creation migration over existing tables. Publish the additive upgrade instead:

```sh
php artisan vendor:publish --provider="Silber\Bouncer\BouncerServiceProvider" --tag="bouncer.upgrade"
php artisan migrate
```

The upgrade adds any missing `role_based`, `restricted_to_id`, and `restricted_to_type` columns and upstream's permission lookup indexes. It preserves existing grants and restrictions and is safe to apply to either upstream or fork schemas. Configure any custom table mappings before running it (and pass `--database=...` if your Bouncer tables live on a non-default connection). It deliberately refuses rollback because those columns may contain pre-existing authorization data; restore a verified backup if a full rollback is needed.

Fresh installations can use the normal `bouncer.migrations` publication tag; their creation migration already contains these columns and indexes. After deploying, clear Bouncer's authorization cache with `Bouncer::refresh()` (for each tenant scope when using an untagged cache).

## Preserved fork APIs

```php
Bouncer::allow('editor')->to('edit-project', null, ['role_based' => true]);
Bouncer::assign('editor')->to($user, $project);
Bouncer::is($user)->on('editor', $project);
Bouncer::is($user)->on(['editor', 'reviewer'], [$projectA, $projectB], 'and');
Bouncer::retract('editor')->from($user, $project);
```

Restrictions can also be model class names, arrays, or collections. Class restrictions cover that model's instances; instance restrictions do not cover other instances. Morph-map aliases are supported, including existing restriction rows stored with concrete class names. Unrestricted roles retain their abilities' original model restrictions. Explicit forbids continue to take precedence.

`canWithOptionalArgs()` and `cannotWithOptionalArgs()` retain the fork's fallback to checking the ability without arguments. As before, that fallback can intentionally allow a global ability even when a model-specific check fails; use ordinary `can()`/`cannot()` when that is not desired.

Role assignment/retraction refreshes affected authorities' cached roles and abilities. Other permission changes retain upstream's explicit cache-refresh behavior. The fork also retains string ability identifiers in authorization response messages and `Helpers::rawQuery()`.