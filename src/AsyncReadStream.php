<?php
namespace GuzzleHttp\Stream;

/**
 * Represents an asynchronous read-only stream that supports a drain event and
 * pumping data from a source stream.
 *
 * The AsyncReadStream can be used as a completely asynchronous stream, meaning
 * the data you can read from the stream will immediately return only
 * the data that is currently buffered.
 *
 * AsyncReadStream can also be used in a "blocking" manner if a "pump" function
 * is provided. When a caller requests more bytes than are available in the
 * buffer, then the pump function is used to block until the requested number
 * of bytes are available or the remote source stream has errored, closed, or
 * timed-out. This behavior isn't strictly "blocking" because the pump function
 * can send other transfers while waiting on the desired buffer size to be
 * ready for reading (e.g., continue to tick an event loop).
 *
 * @unstable This class is subject to change.
 */
class AsyncReadStream implements StreamInterface
{
    /** @var callable|null Fn used to notify writers the buffer has drained */
    private $drain;

    /** @var callable|null Fn used to block for more data */
    private $pump;

    /** @var int|null Highwater mark of the underlying buffer */
    private $hwm;

    /** @var bool Whether or not drain needs to be called at some point */
    private $needsDrain;

    /** @var int The expected size of the remote source */
    private $size;

    /**
     * In order to utilize high water marks to tell writers to slow down, the
     * provided stream must answer to the "hwm" stream metadata variable,
     * providing the high water mark. If no "hwm" metadata value is available,
     * then the "drain" functionality is not utilized.
     *
     * This class accepts an associative array of configuration options.
     *
     * - drain: (callable) Function to invoke when the stream has drained,
     *   meaning the buffer is now writable again because the size of the
     *   buffer is at an acceptable level (e.g., below the high water mark).
     *   The function accepts a single argument, the buffer stream object that
     *   has drained.
     * - pump: (callable) A function that accepts the number of bytes to read
     *   from the source stream. This function will block until all of the data
     *   that was requested has been read, EOF of the source stream, or the
     *   source stream is closed.
     * - size: (int) The expected size in bytes of the data that will be read
     *   (if known up-front).
     *
     * @param StreamInterface $buffer   Buffer that contains the data that has
     *                                  been read by the event loop.
     * @param array           $config   Associative array of options.
     *
     * @throws \InvalidArgumentException if the buffer is not readable and
     *                                   writable.
     */
    public function __construct(
        StreamInterface $buffer,
        array $config = array()
    ) {
        if (!$buffer->isReadable() || !$buffer->isWritable()) {
            throw new \InvalidArgumentException(
                'Buffer must be readable and writable'
            );
        }

        if (isset($config['size'])) {
            $this->size = $config['size'];
        }

        static $callables = array('pump', 'drain');
        foreach ($callables as $check) {
            if (isset($config[$check])) {
                if (!is_callable($config[$check])) {
                    throw new \InvalidArgumentException(
                        $check . ' must be callable'
                    );
                }
                $this->{$check} = $config[$check];
            }
        }

        $this->hwm = $buffer->getMetadata('hwm');

        // Cannot drain when there's no high water mark.
        if ($this->hwm === null) {
            $this->drain = null;
        }

        $this->stream = $buffer;
    }

    /**
     * Factory method used to create new async stream and an underlying buffer
     * if no buffer is provided.
     *
     * This function accepts the same options as AsyncReadStream::__construct,
     * but added the following key value pairs:
     *
     * - buffer: (StreamInterface) Buffer used to buffer data. If none is
     *   provided, a default buffer is created.
     * - hwm: (int) High water mark to use if a buffer is created on your
     *   behalf.
     * - max_buffer: (int) If provided, wraps the utilized buffer in a
     *   DroppingStream decorator to ensure that buffer does not exceed a given
     *   length. When exceeded, the stream will begin dropping data. Set the
     *   max_buffer to 0, to use a NullStream which does not store data.
     * - write: (callable) A function that is invoked when data is written
     *   to the underlying buffer. The function accepts the buffer as the first
     *   argument, and the data being written as the second. The function MUST
     *   return the number of bytes that were written or false to let writers
     *   know to slow down.
     * - drain: (callable) See constructor documentation.
     * - pump: (callable) See constructor documentation.
     *
     * @param array $options Associative array of options.
     *
     * @return array Returns an array containing the buffer used to buffer
     *               data, followed by the ready to use AsyncReadStream object.
     */
    public static function create(array $options = array())
    {
        $maxBuffer = isset($options['max_buffer'])
            ? $options['max_buffer']
            : null;

        if ($maxBuffer === 0) {
            $buffer = new NullStream();
        } elseif (isset($options['buffer'])) {
            $buffer = $options['buffer'];
        } else {
            $hwm = isset($options['hwm']) ? $options['hwm'] : 16384;
            $buffer = new BufferStream($hwm);
        }

        if ($maxBuffer > 0) {
            $buffer = new DroppingStream($buffer, $options['max_buffer']);
        }

        // Call the on_write callback if an on_write function was provided.
        if (isset($options['write'])) {
            $onWrite = $options['write'];
            $buffer = FnStream::decorate($buffer, array(
                'write' => function ($string) use ($buffer, $onWrite) {
                    $result = $buffer->write($string);
                    $onWrite($buffer, $string);
                    return $result;
                }
			));
        }

        return array($buffer, new self($buffer, $options));
    }

    public function getSize()
    {
        return $this->size;
    }

    public function isWritable()
    {
        return false;
    }

    public function write($string)
    {
        return false;
    }

    public function read($length)
    {
        if (!$this->needsDrain && $this->drain) {
            $this->needsDrain = $this->stream->getSize() >= $this->hwm;
        }

        $result = $this->stream->read($length);

        // If we need to drain, then drain when the buffer is empty.
        if ($this->needsDrain && $this->stream->getSize() === 0) {
            $this->needsDrain = false;
            $drainFn = $this->drain;
            $drainFn($this->stream);
        }

        $resultLen = strlen($result);

        // If a pump was provided, the buffer is still open, and not enough
        // data was given, then block until the data is provided.
        if ($this->pump && $resultLen < $length) {
            $pumpFn = $this->pump;
            $result .= $pumpFn($length - $resultLen);
        }

        return $result;
    }

    /** From Trait */

    /**
	 * Magic method used to create a new stream if streams are not added in
	 * the constructor of a decorator (e.g., LazyOpenStream).
	 */
	public function __get($name)
	{
		if ($name == 'stream') {
			$this->stream = $this->createStream();
			return $this->stream;
		}

		throw new \UnexpectedValueException("$name not found on class");
	}

	public function __toString()
	{
		try {
			$this->seek(0);
			return $this->getContents();
		} catch (\Exception $e) {
			// Really, PHP? https://bugs.php.net/bug.php?id=53648
			trigger_error('StreamDecorator::__toString exception: '
				. (string) $e, E_USER_ERROR);
			return '';
		}
	}

	public function getContents()
	{
		return Utils::copyToString($this);
	}

	/**
	 * Allow decorators to implement custom methods
	 *
	 * @param string $method Missing method name
	 * @param array  $args   Method arguments
	 *
	 * @return mixed
	 */
	public function __call($method, array $args)
	{
		$result = call_user_func_array(array($this->stream, $method), $args);

		// Always return the wrapped object if the result is a return $this
		return $result === $this->stream ? $this : $result;
	}

	public function close()
	{
		$this->stream->close();
	}

	public function getMetadata($key = null)
	{
		return $this->stream->getMetadata($key);
	}

	public function detach()
	{
		return $this->stream->detach();
	}

	public function attach($stream)
	{
		throw new CannotAttachException();
	}

	public function eof()
	{
		return $this->stream->eof();
	}

	public function tell()
	{
		return $this->stream->tell();
	}

	public function isReadable()
	{
		return $this->stream->isReadable();
	}

	public function isSeekable()
	{
		return $this->stream->isSeekable();
	}

	public function seek($offset, $whence = SEEK_SET)
	{
		return $this->stream->seek($offset, $whence);
	}

	/**
	 * Implement in subclasses to dynamically create streams when requested.
	 *
	 * @return StreamInterface
	 * @throws \BadMethodCallException
	 */
	protected function createStream()
	{
		throw new \BadMethodCallException('createStream() not implemented in '
			. get_class($this));
	}
}
