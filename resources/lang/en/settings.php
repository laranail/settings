<?php

declare(strict_types=1);

/*
 * laranail/settings: every message the package shows a person. Registered under the
 * `laranail/settings` translation namespace, read through Laravel's translator, and
 * publishable with `php artisan vendor:publish --tag=laranail::settings-translations`.
 *
 * Exception messages are not here: they name stores, keys and scopes, and are written for the
 * developer reading a log. Placeholders (`:name`) are filled in by the translator; a line with a
 * `|` is a plural pair (one, other).
 */
return [
    // RFC 9457 problem details from the REST API: a title per problem type, and the detail of
    // the problems that do not carry an exception's own message.
    'problems' => [
        'titles' => [
            'access-denied'           => 'Access denied',
            'actor-required'          => 'Actor required',
            'verification-required'   => 'Verification required',
            'unknown-store'           => 'Unknown store',
            'unknown-setting'         => 'Unknown setting',
            'unknown-owner'           => 'Unknown owner',
            'unknown-category'        => 'Unknown category',
            'unknown-change-request'  => 'Unknown change request',
            'invalid-value'           => 'Invalid value',
            'invalid-identifier'      => 'Invalid identifier',
            'invalid-import'          => 'Invalid import',
            'invalid-definition'      => 'Invalid definition',
            'invalid-configuration'   => 'Invalid configuration',
            'type-mismatch'           => 'Type mismatch',
            'category-cycle'          => 'Category cycle',
            'category-store-mismatch' => 'Category store mismatch',
            'locked'                  => 'Locked',
            'precondition-failed'     => 'Precondition failed',
            'approval-unavailable'    => 'Approval unavailable',
            'change-request-closed'   => 'Change request closed',
            'owner-not-allowed'       => 'Owner not allowed',
            'store-not-writable'      => 'Store not writable',
            'history-unavailable'     => 'History unavailable',
            'unavailable'             => 'Unavailable',
            'validation'              => 'Validation',
            'unauthenticated'         => 'Unauthenticated',
            'too-many-requests'       => 'Too many requests',
            'settings-error'          => 'Settings error',
        ],
        'validation'        => 'The request is invalid.',
        'unauthenticated'   => 'Authentication is required.',
        'too-many-requests' => 'Too many requests.',
        'http'              => 'The request failed.',
        'settings-error'    => 'The settings operation failed.',
    ],

    // The SettingsAlert notification (mail, Slack). Identifiers only: an alert never carries a value.
    'alert' => [
        'labels' => [
            'event'   => 'Event',
            'store'   => 'Store',
            'key'     => 'Key',
            'scope'   => 'Scope',
            'locale'  => 'Locale',
            'actor'   => 'Actor',
            'outcome' => 'Outcome',
            'at'      => 'At',
        ],
        'yes'    => 'yes',
        'no'     => 'no',
        'footer' => 'Values are never included in alerts; read the setting or its history to see what changed.',
    ],

    // Artisan command output.
    'console' => [
        'cache_reset'          => 'Settings cache reset (:stores).',
        'cache_every_store'    => 'every store',
        'cache_off'            => 'Caching is off; nothing to reset.',
        'definitions_synced'   => 'Synced :synced definition(s); removed :removed stale system row(s).',
        'definitions_skipped'  => 'Store [:store] skipped: no active database layer, or the definitions table is off.',
        'definitions_busy'     => 'Another sync-definitions run holds the lock; this one changed nothing. Run it again once that one finishes.',
        'definitions_conflict' => '[:key] is defined in code and edited in the table; code wins, the row was left alone.',
        'diff_none'            => 'No differences (:unchanged setting(s) equal).',
        'diff_summary'         => ':added to add, :removed only in the store, :changed changed, :unknown not comparable (redacted).',
        'history_none'         => 'No history for [:key].',
        'history_diff_usage'   => '--diff takes two versions, FROM..TO (for example 3..5).',
        'history_changed'      => 'The value changed.',
        'history_same'         => 'The value is the same.',
        'rollback_usage'       => '--rollback takes a version number.',
        'rolled_back'          => 'Rolled [:key] back to version :version; it is now at version :new.',
        'promoted'             => 'Store [:store]: :promoted promoted, :skipped skipped.',
        'promote_archived'     => 'The runtime file was archived as [:archive]; skipped entries remain there.',
        'orphans_unmapped'     => 'Alias [:alias] has no model in the morph map; its settings were not checked.',
        'orphans_none'         => 'No orphaned settings.',
        'orphans_dry_run'      => ':count orphaned owner(s) found; nothing removed (dry run).',
        'orphans_removed'      => 'Removed :removed row(s) of :count orphaned owner(s).',
        'warm_none'            => 'No store is cached (caching is off, or no store has a database layer); nothing to warm.',
        'import_applied'       => 'Imported:',
        'import_dry_run'       => 'Dry run (add --apply to write):',
        'imported'             => ':prefix :created created, :updated updated, :unchanged unchanged, :skipped skipped; :count category created.|:prefix :created created, :updated updated, :unchanged unchanged, :skipped skipped; :count categories created.',
        'import_skipped'       => ':identity skipped: :reason',
        'import_proposed'      => ':count setting(s) need approval: change requests :ids.',
        'store_created'        => 'Created :path',
        'store_next_step'      => 'Add this to your configuration, then run the migrations:',
        'audit_days_invalid'   => 'The retention period must be a whole number of days.',
        'audit_pruned'         => 'Removed :count audit entry older than :days day(s).|Removed :count audit entries older than :days day(s).',
        'changes_pruned'       => 'Expired :expired change request(s); deleted :deleted decided one(s).',
        'history_invalid'      => 'The retention period and the number of versions must be whole numbers.',
        'history_unconfigured' => 'No history retention is configured (history.keep_days and history.keep_versions are null); nothing to prune.',
        'history_pruned'       => 'Removed :count history entry.|Removed :count history entries.',
        'export_confirm'       => 'This writes decrypted secrets. Type the store name [:store] to continue',
        'export_mismatch'      => 'The store name did not match; nothing was written.',
        'exported'             => 'Exported :settings setting(s) and :count category of store [:store] to :path.|Exported :settings setting(s) and :count categories of store [:store] to :path.',
        'export_ciphertext'    => 'Encrypted values are ciphertext: importable only under the same APP_KEY, or one listed in APP_PREVIOUS_KEYS.',
        'reencrypted'          => 'Re-encrypted :values value(s), :count history entry and :changes change request(s).|Re-encrypted :values value(s), :count history entries and :changes change request(s).',
        'reencrypt_failed'     => ':failed payload(s) could not be decrypted with the current or previous keys and were left as they were.',
        'rescoped'             => 'Renamed [:from] to [:to] in :values value(s), :count history entry and :changes change request(s). Update the stores\' owners lists to match.|Renamed [:from] to [:to] in :values value(s), :count history entries and :changes change request(s). Update the stores\' owners lists to match.',
    ],
];
