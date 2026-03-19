<?php
/**
 * Bouncer Plugin Configuration
 *
 * This file contains configuration options for the Bouncer plugin.
 */

return [
    'Bouncer' => [
        /**
         * Admin Layout Configuration
         *
         * Controls which layout is used for the admin interface:
         * - null (default): Uses the plugin's isolated Bootstrap 5 layout ('Bouncer.bouncer')
         *   This is a self-contained layout that doesn't depend on the host application's styling.
         * - false: Disables the plugin layout, uses the app's default layout.
         *   Use this when you want to integrate with your existing admin theme.
         * - string: Uses the specified layout (e.g., 'Admin.default', 'MyTheme.admin')
         */
        'adminLayout' => null,

        /**
         * Standalone Mode
         *
         * When enabled, the admin interface operates independently without
         * inheriting from AppController's authentication/authorization.
         * Useful for quick setup or when using separate admin authentication.
         */
        'standalone' => false,

        /**
         * User Link Configuration
         *
         * Configure how user IDs are linked in the bouncer record display.
         * - String pattern: '/admin/users/view/{user}' (placeholders: {user}, {display})
         * - Array URL: ['prefix' => 'Admin', 'controller' => 'Users', 'action' => 'view', '{user}']
         * - Callable: function($userId, $userDisplay) { return '/admin/users/' . $userId; }
         * - null: No linking (default)
         */
        'linkUser' => null,

        /**
         * Record Link Configuration
         *
         * Configure how source record IDs are linked in the bouncer display.
         * - String pattern: '/admin/{source}/view/{primary_key}'
         * - Array URL: ['prefix' => 'Admin', 'controller' => '{source}', 'action' => 'view', '{primary_key}']
         * - Callable: function($source, $primaryKey) { return [...]; }
         * - null: No linking (default)
         */
        'linkRecord' => null,
    ],
];
