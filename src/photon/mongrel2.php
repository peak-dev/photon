<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of Photon, the High Speed PHP Framework.
# Copyright (C) 2010 Loic d'Anterroches and contributors.
#
# Photon is free software; you can redistribute it and/or modify
# it under the terms of the GNU Lesser General Public License as published by
# the Free Software Foundation; either version 2.1 of the License.
#
# Photon is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
#
# ***** END LICENSE BLOCK ***** */

/**
 * Mongrel2 Interface.
 *
 * This namespace groups all the functions and classes for the
 * connection between the PHP application server and Mongrel2. What is
 * important to notice is that most of the work is done lazily to
 * really parse the needed data only on demand.
 */
namespace photon\mongrel2;

/**
 * Parse a netstring.
 *
 * The only problem with this function is that you have many copies of
 * the data in memory and this can create a bit of memory consumption.
 *
 */
function parse_netstring($ns)
{
    list($len, $rest) = \explode(':', $ns, 2);
    unset($ns);
    $len = (int) $len;
    return array(
        \substr($rest, 0, $len),
        \substr($rest, $len + 1)
    );
}

/**
 * Wraps the Mongrel2 message to the application server.
 */
class Message
{
    public $sender;
    public $path;
    public $conn_id;
    public $headers;
    public $body; /**< mixed A handler to the in memory storage of the
                   *  body, an empty string or a decoded JSON string.
                   */

    /**
     * Called by self::parse
     */
    public function __construct($sender, $conn_id, $path, $headers, $body)
    {
        $this->sender = $sender;
        $this->path = $path;
        $this->conn_id = $conn_id;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function is_disconnect()
    {
        return (isset($this->headers->METHOD)
                && 'JSON' === $this->headers->METHOD
                && 'disconnect' === $this->body->type);
    }

    /**
     * We close the stream when the message is discarded.
     *
     * This is necessary to avoid accumulation of temp memory segment usage.
     */
    public function __destruct()
    {
        if (is_resource($this->body)) {
            @fclose($this->body);
        }
    }

    public static function parse($msg)
    {
        list($sender, $conn_id, $path, $msg) = \explode(' ', $msg, 4);
        list($headers, $msg) = parse_netstring($msg);
        list($body, ) = parse_netstring($msg);
        unset($msg);
        $headers = \json_decode($headers);

        return new Message($sender, $conn_id, $path, $headers, $body);
    }
}

/**
 * ZMQ connection between Mongrel2 and the application server.
 *
 * The connection is used to retrieve a request and send a
 * response. The connection is getting the sockets for reading and
 * writing. They are already instanciated by the server class.
 */
class Connection
{
    public $pull_socket;
    public $pub_socket;

    public function __construct($pull_socket, $pub_socket)
    {
        $this->pull_socket = $pull_socket;
        $this->pub_socket = $pub_socket;
    }

    /**
     * Receive the data from the zeromq backend.
     *
     * Before receiving the data, we have no idea about the real size
     * of the data we are getting. The goal is to be smart and avoid
     * crashing PHP under the load when getting a 50MB or more upload.
     *
     * The fastest solution is to do all in memory and consider the
     * request as a nice string. The safest solution is to put
     * everything in a stream which will write the data on disk if too
     * large (let say 5MB) but else operate in memory and be smart in
     * parsing the request to not load everything in memory.
     *
     */
    public function recv() 
    {
        $fp = fopen('php://temp/maxmemory:5242880', 'r+');
        fputs($fp, $this->pull_socket->recv());
        rewind($fp);

        return $this->parse($fp);
    }

    /**
     * Parse the Mongrel2 request and returns a message.
     *
     * @param $fp Open file descriptor to access the message.
     * @return Message object.
     */
    public function parse($fp)
    {
        $body = null;
        $line = fread($fp, 8192);
        list($sender, $conn_id, $path, $smsg) = \explode(' ', $line, 4);
         // From $smsg, get the size of the headers
        list($len, $rest) = \explode(':', $smsg, 2);
        unset($smsg);
        $len = (int) $len;
        $rlen = strlen($rest);
        if ($rlen > $len) {
            $headers = \json_decode(\substr($rest, 0, $len));
            fseek($fp, -$rlen + $len + 1, SEEK_CUR);
        } else {
            // Need to grab the end of the headers
            $toread = $len - $rlen;
            $headers = \json_decode($rest . fread($fp, $toread));
            fread($fp, 1); // The comma
        }
        // Now the body of the request is available by just doing a simple:
        // $body = stream_get_contents($fp);

        // This makes sense if we do not have file upload. With file
        // uploads, we should provide them as file handlers ready to
        // be stored somewhere else.

        // We are going to support only the POST and JSON requests at
        // the moment.
        if ('JSON' === $headers->METHOD) {
            // small request normally
            list($body,) = parse_netstring(stream_get_contents($fp));
            $body = json_decode($body);
            fclose($fp);
        } elseif ('POST' === $headers->METHOD 
                  || (isset($headers->{'content-length'}) 
                      && 0 < (int) $headers->{'content-length'})) {
            // Here the parsing of the body should be done.
            //$body = stream_get_contents($fp);
            // just to get the position of the real start of the body
            $line = fread($fp, 100);
            list($len, $rest) = \explode(':', $line, 2);
            fseek($fp, -strlen($rest), SEEK_CUR);
            // The body is parsed in the \photon\http\Request class,
            // only if needed. 
            $body = $fp;
        } else {
            $body = '';
            fclose($fp);
        } 

        return new Message($sender, $conn_id, $path, $headers, $body);
    }

    /**
     * Reply to the listener which generated the request.
     *
     * The listener is the one defined in the message.
     *
     * @param $mess Message 
     * @param $payload What to send to the listener
     * @return bool
     */
    public function reply($mess, $payload)
    {
        return $this->send($mess->sender, $mess->conn_id, $payload);
    }

    /**
     * Same as reply() but let the response object send.
     *
     * We call sendIterable() on the response object. This way the
     * response object can stream large chunk in many small send()
     * calls. Of course it blocks the handler, but this is not
     * necessarily an issue.
     */
    public function replyResponse($mess, $response)
    {
        $response->sendIterable($mess, $this);
    }

    /**
     * Send a payload to a listener.
     *
     * It is publishing the payload on the pub socket and let the
     * right Mongrel2 server pick it based on the UUID used as
     * subscription.
     *
     * Use deliver() to send to many listeners.
     *
     * @param $uuid UUID of the Mongrel2 server to send the payload to
     * @param $listener Listener
     * @param $payload Just what to send
     * @return bool Success
     */
    public function send($uuid, $listener, $payload)
    {
        return send($this->pub_socket, $uuid, $listener, $payload);
    }

    /**
     * Send the payload back to a list of listeners.
     *
     * @param $uuid ID of the sender
     * @param $listeners Array of the listeners connection ids
     * @param $payload Payload
     */
    public function deliver($uuid, $listeners, $payload)
    {
        return deliver($this->pub_socket, $uuid, $listeners, $payload);
    }
}

/**
 * Send an answer over the socket.
 *
 * @param $socket ZMQ socket providing the send() method
 * @param $uuid UUID of the server for subscription
 * @param $listeners Space delimited list of listeners 
 * @param $msg Payload
 */
function send($socket, $uuid, $listeners, $msg)
{
    $header = \sprintf('%s %d:%s,', $uuid, \strlen($listeners), $listeners);
    return $socket->send($header . ' ' . $msg);
}

/**
 * Deliver a piece of data to a series of listeners.
 *
 * @param $socket The delivery socket
 * @param $uuid ID of the sender
 * @param $lids Array of the listeners connection ids
 * @param $payload Payload
 */
function deliver($socket, $uuid, $lids, $payload)
{
    if (129 > count($lids)) {
        return send($socket, $uuid, \join(' ', $lids),  $payload);
    }
    // We need to send multiple times the data. We are going to send
    // the data in series of 128 to the clients. 128 is the default
    // maximum number of listeners which can be addressed in one go
    // with Mongrel2. This value can be changed in the configuration. 
    $a = 1;
    foreach (array_chunk($lids, 128) as $chunk) {
        $a = $a & (int) send($socket, $uuid, \join(' ', $chunk),  $payload);
    }
    return (bool) $a;
}
