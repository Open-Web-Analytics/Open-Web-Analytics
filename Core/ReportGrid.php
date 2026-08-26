<?php
namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * Where a report's widgets sit.
 *
 * A report is a grid of widgets, and each widget says how wide and how tall it
 * is in grid cells. This class turns those numbers into the class names the
 * stylesheet understands, and refuses the ones that would quietly break the
 * layout.
 *
 * CLASSES, NOT INLINE STYLES. An inline `grid-column` beats anything a
 * stylesheet can say without !important, which would leave the container
 * queries in owa.report.css unable to collapse the grid on a narrow screen.
 * Emitting a class keeps the responsive behaviour in CSS, where it belongs.
 *
 * The clamp is the part that matters. A span wider than the grid does NOT
 * overflow and does not get truncated: CSS grid creates implicit columns to fit
 * it, so the whole grid silently becomes wider and every OTHER widget is
 * resized to match. The symptom appears far from the cause -- one bad number in
 * one definition, and a different report's chart is the wrong size. So an
 * over-wide span becomes full-bleed instead, which is what the author meant.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 */
class ReportGrid {

    /**
     * Columns in the widest layout.
     *
     * Twelve because it divides by 2, 3, 4 and 6, so halves, thirds and
     * quarters are all expressible without fractions.
     */
    const COLUMNS = 12;

    /** A widget taller than this is almost certainly a typo, not a design. */
    const MAX_ROWSPAN = 6;

    /**
     * The column span a widget actually gets.
     *
     * Absent means full width: most report widgets are full width, and a format
     * where the common case needs no ceremony is one people get right.
     *
     * @param array $widget
     * @return int between 1 and COLUMNS
     */
    public static function colspan( $widget ) {

        $widget = (array) $widget;

        if ( ! isset( $widget['colspan'] ) || $widget['colspan'] === '' ) {

            return self::COLUMNS;
        }

        $span = (int) $widget['colspan'];

        if ( $span < 1 ) {

            // Zero or negative would make grid-column: span 0, which the
            // browser treats as span 1 -- a widget silently one cell wide
            // rather than an error anyone would notice.
            return self::COLUMNS;
        }

        return $span > self::COLUMNS ? self::COLUMNS : $span;
    }

    /**
     * The row span a widget actually gets.
     *
     * @param array $widget
     * @return int between 1 and MAX_ROWSPAN
     */
    public static function rowspan( $widget ) {

        $widget = (array) $widget;

        if ( ! isset( $widget['rowspan'] ) || $widget['rowspan'] === '' ) {

            return 1;
        }

        $span = (int) $widget['rowspan'];

        if ( $span < 1 ) {

            return 1;
        }

        return $span > self::MAX_ROWSPAN ? self::MAX_ROWSPAN : $span;
    }

    /**
     * Whether a widget was asked to be wider than the grid.
     *
     * Kept separate from colspan() so the caller can say so -- a definition
     * asking for 16 of 12 columns is a mistake worth reporting, even though
     * rendering it as full width is the right recovery.
     *
     * @param array $widget
     * @return bool
     */
    public static function isOverWide( $widget ) {

        $widget = (array) $widget;

        return isset( $widget['colspan'] ) && (int) $widget['colspan'] > self::COLUMNS;
    }

    /**
     * The class attribute for one widget's grid cell.
     *
     * @param array $widget
     * @return string
     */
    public static function classesFor( $widget ) {

        $classes = array( 'owa_reportGridItem' );

        $classes[] = 'owa_span-' . self::colspan( $widget );

        $rowspan = self::rowspan( $widget );

        if ( $rowspan > 1 ) {

            $classes[] = 'owa_rowspan-' . $rowspan;
        }

        return implode( ' ', $classes );
    }

    /**
     * Every span class the stylesheet has to define.
     *
     * Generated rather than listed so the CSS and this class cannot disagree
     * about how many columns there are -- a missing .owa_span-7 would leave a
     * widget at its default width with nothing to say why.
     *
     * @return array<int, string>
     */
    public static function spanClasses() {

        $classes = array();

        for ( $i = 1; $i <= self::COLUMNS; $i++ ) {

            $classes[] = 'owa_span-' . $i;
        }

        for ( $i = 2; $i <= self::MAX_ROWSPAN; $i++ ) {

            $classes[] = 'owa_rowspan-' . $i;
        }

        return $classes;
    }
}
