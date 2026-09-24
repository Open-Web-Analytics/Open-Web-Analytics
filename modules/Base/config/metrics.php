<?php

/**
 * The metrics that read a Property's reporting cube.
 *
 * A definition names a column and a KIND the query builder already knows how to
 * render, so it carries no SQL -- which is what makes it a file rather than a
 * class. Core\Module::registerMetricsFromConfig() picks this up.
 *
 * `eventCount` is every row, which is what the cube makes cheap and what 1.x
 * could not express: its `actions` counted one fact table.
 *
 * A NAME REACHES THE CUBE ONLY THROUGH A METRIC. ResultSetManager reduces the
 * candidate entities to those that can compute every requested metric, so until
 * a metric here names the cube, no query can select it however many dimensions
 * it carries.
 */
return array(

    'entity' => 'base.event',

    'metrics' => array(

        'eventCount' => array(
            'label'       => 'Events',
            'description' => 'The total number of events.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
        ),
    ),
);
