<?php
/*
 * The status badge both cube screens use: a dot and a word, never the colour
 * alone. Each variant's markup is written out in full so every class it
 * carries is literal -- TemplateClassesAreStyledTest reads them from here.
 */
if ( ! function_exists( 'owa_cube_status_badge' ) ) {

    function owa_cube_status_badge( $level ) {

        switch ( $level ) {

            case 'red':
                return '<span class="owa_cubeStatus owa_cubeStatusRed"><span class="owa_cubeStatusDot"></span>Action needed</span>';

            case 'yellow':
                return '<span class="owa_cubeStatus owa_cubeStatusYellow"><span class="owa_cubeStatusDot"></span>Attention</span>';

            default:
                return '<span class="owa_cubeStatus owa_cubeStatusGreen"><span class="owa_cubeStatusDot"></span>OK</span>';
        }
    }
}
