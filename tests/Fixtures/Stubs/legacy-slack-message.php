<?php

declare(strict_types=1);

namespace Illuminate\Notifications\Messages;

/*
 * A stand-in for laravel/slack-notification-channel's legacy SlackMessage, which this package only
 * suggests. Loaded by the alert tests that exercise the Slack route; never by the package.
 */
if (! class_exists(SlackMessage::class)) {
    final class SlackMessage
    {
        public string $content = '';

        public function content(string $content): self
        {
            $this->content = $content;

            return $this;
        }
    }
}
