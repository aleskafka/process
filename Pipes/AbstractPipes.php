<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Process\Pipes;

/**
 * @author Romain Neutron <imprec@gmail.com>
 *
 * @internal
 */
abstract class AbstractPipes implements PipesInterface
{
    /** @var array */
    public $pipes = array();

    /** @var string */
    protected $inputBuffer = '';
    /** @var resource|null */
    protected $input;

    /** @var bool */
    private $blocked = true;

    public function __construct($input)
    {
        if (is_resource($input)) {
            $this->input = $input;
        } elseif (is_string($input)) {
            $this->inputBuffer = $input;
        } else {
            $this->inputBuffer = (string) $input;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = array();
    }

    /**
     * Returns true if a system call has been interrupted.
     *
     * @return bool
     */
    protected function hasSystemCallBeenInterrupted()
    {
        $lastError = error_get_last();

        // stream_select returns false when the `select` system call is interrupted by an incoming signal
        return isset($lastError['message']) && false !== stripos($lastError['message'], 'interrupted system call');
    }

    /**
     * Unblocks streams.
     */
    protected function unblock()
    {
        if (!$this->blocked) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, 0);
        }
        if (null !== $this->input) {
            stream_set_blocking($this->input, 0);
        }

        $this->blocked = false;
    }

    /**
     * Writes input to stdin.
     */
    protected function writeInput()
    {
        if (!isset($this->pipes[0])) {
            return;
        }

        $e = array();
        $r = null !== $this->input ? array($this->input) : $e;
        $w = array($this->pipes[0]);

        // let's have a look if something changed in streams
        if (false === $n = @stream_select($r, $w, $e, 0, 0)) {
            return;
        }

        foreach ($w as $stdin) {
            while (strlen($this->inputBuffer)) {
                $written = fwrite($stdin, $this->inputBuffer, 2 << 18); // write 512k
                if ($written > 0) {
                    $this->inputBuffer = (string) substr($this->inputBuffer, $written);
                } else {
                    break;
                }
            }

            foreach ($r as $input) {
                for (;;) {
                    $data = fread($input, self::CHUNK_SIZE);
                    if (!isset($data[0])) {
                        break;
                    }
                    $written = fwrite($stdin, $data);
                    $data = substr($data, $written);
                    if (isset($data[0])) {
                        $this->inputBuffer = $data;

                        return array($this->pipes[0]);
                    }
                }
                if (!isset($data[0]) && feof($input)) {
                    // no more data to read on input resource
                    // use an empty buffer in the next reads
                    $this->input = null;
                }
            }
        }

        // no input to read on resource, buffer is empty
        if (null === $this->input && !isset($this->inputBuffer[0])) {
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
        }

        if (!$w) {
            return array($this->pipes[0]);
        }
    }
}
