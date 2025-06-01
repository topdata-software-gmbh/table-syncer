<?php

declare(strict_types=1);

namespace TopdataSoftwareGmbh\TableSyncer\Enum;

/**
 * Enum representing the type of source object being introspected.
 */
enum SourceObjectTypeEnum: string
{
    case TABLE                                  = 'TABLE';
    case VIEW                                   = 'VIEW';
    case INTROSPECTABLE_OBJECT_UNDETERMINED     = 'INTROSPECTABLE OBJECT (type undetermined)';
    case INTROSPECTABLE_OBJECT_NOT_IN_LISTVIEWS = 'INTROSPECTABLE OBJECT (type undetermined, not in listViews)';
    case UNKNOWN                                = 'UNKNOWN';
}
