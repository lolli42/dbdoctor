<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Lolli\Dbdoctor\Event;

/**
 * Event to modify the main list of health classes: Remove, add or exchange health checks.
 *
 * Only use if you know exactly what you are doing. Keep this in mind:
 *
 * * The health check list is run top->bottom and later ones depend on db state established by previous ones.
 *   Blindly munging this list will create hazard.
 * * The list itself is not API. dbdoctor happily adds, renames, or removes checks any time at any position.
 *   Sanitize listeners well when manipulating the list. Throw exceptions when something unexpected happens.
 * * Nothing of dbdoctor is API, at least for now and the foreseeable future. The various helper classes may
 *   or may not change anytime, even HealthCheckInterface may change without further notice.
 * * It is a very good idea to establish tests backed by regularly scheduled CI runs to verify your event
 *   listener continues to work with new dbdoctor releases.
 */
final class ModifyHealthClassListEvent
{
    /**
     * @param string[] $healthClasses
     */
    public function __construct(
        public array $healthClasses,
    ) {}
}
