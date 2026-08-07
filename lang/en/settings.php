<?php

declare(strict_types=1);

return [
    'title' => 'Settings',
    'description' => 'Manage your profile and account settings',
    'saved' => 'Saved',

    'nav' => [
        'profile' => 'Profile',
        'password' => 'Password',
        'appearance' => 'Appearance',
    ],

    'profile' => [
        'breadcrumb' => 'Profile settings',
        'title' => 'Profile information',
        'description' => 'Update your name and email address',
        'name' => 'Name',
        'name_placeholder' => 'Full name',
        'email' => 'Email address',
        'email_placeholder' => 'Email address',
        'unverified' => 'Your email address is unverified.',
        'resend' => 'Click here to re-send the verification email.',
        'verification_sent' => 'A new verification link has been sent to your email address.',
        'save' => 'Save',
    ],

    'password' => [
        'breadcrumb' => 'Password settings',
        'title' => 'Update password',
        'description' => 'Ensure your account is using a long, random password to stay secure',
        'current' => 'Current password',
        'new' => 'New password',
        'confirm' => 'Confirm password',
        'save' => 'Save password',
    ],

    'appearance' => [
        'breadcrumb' => 'Appearance settings',
        'title' => 'Appearance settings',
        'description' => "Update your account's appearance settings",
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ],

    'delete' => [
        'title' => 'Delete account',
        'description' => 'Delete your account and all of its resources',
        'warning' => 'Warning',
        'warning_detail' => 'Please proceed with caution, this cannot be undone.',
        'button' => 'Delete account',
        'confirm_title' => 'Are you sure you want to delete your account?',
        'confirm_description' => 'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
        'password' => 'Password',
        'cancel' => 'Cancel',
    ],
];
