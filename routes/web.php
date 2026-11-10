<?php

declare(strict_types=1);

/*
 * laranail/settings web routes. Not loaded: the package is headless (design §16.1), so it serves
 * no pages and this file holds no routes.
 *
 * A route belongs here only if it answers a browser rather than an API client, such as a signed
 * link an alert mail points at, and it is named `laranail-settings.web.<name>`. To opt in, add the
 * route here and load the file from SettingsServiceProvider::packageBooted(), behind the
 * `http.enabled` switch, with `$this->loadRoutesFrom($this->package->basePath('/routes/web.php'))`.
 * An admin interface is a separate companion package, never this file.
 */
