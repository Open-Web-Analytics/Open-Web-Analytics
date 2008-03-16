<?php
/**
 * $Header: /repository/pear/Log/Log/composite.php,v 1.28 2006/06/29 07:12:34 jon Exp $
 * $Horde: horde/lib/Log/composite.php,v 1.2 2000/06/28 21:36:13 jon Exp $
 *
 * @version $Revision: 1.28 $
 * @package Log
 */

/**
 * The Log_composite:: class implements a Composite pattern which
 * allows multiple Log implementations to receive the same events.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jon Parise <jon@php.net>
 *
 * @since Horde 1.3
 * @since Log 1.0
 * @package Log
 *
 * @example composite.php   Using the composite handler.
 */
class Log_composite extends Log
{
    /**
     * Array holding all of the Log instances to which log events should be
     * sent.
     *
     * @var array
     * @access private
     */
    var $_children = array();


    /**
     * Constructs a new composite Log object.
     *
     * @param boolean   $name       This parameter is ignored.
     * @param boolean   $ident      This parameter is ignored.
     * @param boolean   $conf       This parameter is ignored.
     * @param boolean   $level      This parameter is ignored.
     *
     * @access public
     */
    function Log_composite($name, $ident = '', $conf = array(),
                           $level = PEAR_LOG_DEBUG)
    {
        $this->_ident = $ident;
    }

    /**
     * Opens all of the child instances.
     *
     * @return  True if all of the child instances were successfully opened.
     *
     * @access public
     */
    function open()
    {
        /* Attempt to open each of our children. */
        $this->_opened = true;
        foreach ($this->_children as $id => $child) {
            $this->_opened &= $this->_children[$id]->open();
        }

        /* If all children were opened, return success. */
        return $this->_opened;
    }

    /**
     * Closes all of the child instances.
     *
     * @return  True if all of the child instances were successfully closed.
     *
     * @access public
     */
    function close()
    {
        /* Attempt to close each of our children. */
        $closed = true;
        foreach ($this->_children as $id => $child) {
            $closed &= $this->_children[$id]->close();
        }

        /* Track the _opened state for consistency. */
        $this->_opened = false;

        /* If all children were closed, return success. */
        return $closed;
    }

    /**
     * Flushes all child instances.  It is assumed that all of the children
     * have been successfully opened.
     *
     * @return  True if all of the child instances were successfully flushed.
     *
     * @access public
     * @since Log 1.8.2
     */
    function flush()
    {
        /* Attempt to flush each of our children. */
        $flushed = true;
        foreach ($this->_children as $id => $child) {
            $flushed &= $this->_children[$id]->flush();
        }

        /* If all children were flushed, return success. */
        return $flushed;
    }

    /**
     * Sends $message and $priority to each child of this composite.  If the
     * children aren't already open, they will be opened here.
     *
     * @param mixed     $message    String or object containing the message
     *                              to log.
     * @param string    $priority   (optional) The priority of the message.
     *                              Valid values are: PEAR_LOG_EMERG,
     *                              PEAR_LOG_ALERT, PEAR_LOG_CRIT,
     *                              PEAR_LOG_ERR, PEAR_LOG_WARNING,
     *                              PEAR_LOG_NOTICE, PEAR_LOG_INFO, and
     *                              PEAR_LOG_DEBUG.
     *
     * @return boolean  True if the entry is successfully logged.
     *
     * @access public
     */
    function log($message, $priority = null)
    {
        /* If a priority hasn't been specified, use the default value. */
        if ($priority === null) {
            $priority = $this->_priority;
        }

        /*
         * If the handlers haven't been opened, attempt to open them now.
         * However, we don't treat failure to open all of the handlers as a
         * fatal error.  We defer that consideration to the success of calling
         * each handler's log() method below.
         */
        if (!$this->_opened) {
            $this->open();
        }

        /* Attempt to log the event using each of the children. */
        $success = true;
        foreach ($this->_children as $id => $child) {
            $success &= $this->_children[$id]->log($message, $priority);
        }

        $this->_announce(array('priority' => $priority, 'message' => $message));

        /* Return success if all of the children logged the event. */
        return $success;
    }

    /**
     * Returns true if this is a composite.
     *
     * @return boolean  True if this is a composite class.
     *
     * @access public
     */
    function isComposite()
    {
        return true;
    }

    /**
     * Sets this identification string for all of this composite's children.
     *
     * @param string    $ident      The new identification string.
     *
     * @access public
     * @since  Log 1.6.7
     */
    function setIdent($ident)
    {
        /* Call our base class's setIdent() method. */
        parent::setIdent($ident);

        /* ... and then call setIdent() on all of our children. */
        foreach ($this->_children as $id => $child) {
            $this->_children[$id]->setIdent($ident);
        }
    }

    /**
     * Adds a Log instance to the list of children.
     *
     * @param object    $child      The Log instance to add.
     *
     * @return boolean  True if the Log instance was successfully added.
     *
     * @access public
     */
    function addChild(&$child)
    {
        /* Make sure this is a Log instance. */
        if (!is_a($child, 'Log')) {
            return false;
        }

        $this->_children[$child->_id] = &$child;

        return true;
    }

    /**
     * Removes a Log instance from the list of children.
     *
     * @param object    $child      The Log instance to remove.
     *
     * @return boolean  True if the Log instance was successfully removed.
     *
     * @access public
     */
    function removeChild($child)
    {
        if (!is_a($child, 'Log') || !isset($this->_children[$child->_id])) {
            return false;
        }

        unset($this->_children[$child->_id]);

        return true;
    }

}
