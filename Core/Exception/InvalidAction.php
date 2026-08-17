<?php
namespace OWA\Core\Exception;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * The requested action is not a well-formed action name.
 *
 * Distinct from "no controller answers to it": a name that is not
 * <module>.<action>, or whose segments are not bare identifiers, was never a
 * route on any installation, so it is a bad request rather than a missing page.
 * The two are separated here because the caller turns them into different
 * status codes, and matching on an exception message to tell them apart would
 * break the moment the wording changed.
 *
 * The rejected value is deliberately not carried in the message that reaches a
 * visitor -- it is request-supplied, and the response must not reflect it.
 */
class InvalidAction extends \Exception {

}
