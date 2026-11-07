<?php

namespace App\Classes;

/**
 * Whether a shared create/edit page was opened to make something new or to change
 * what exists (Ketura, 2026-11-06): each thing gets one page, and its template drops
 * or disables the controls the mode doesn't allow, rather than two pages drifting
 * apart.
 */
enum FormMode: string
{
    case Create = 'create';
    case Edit = 'edit';
}
