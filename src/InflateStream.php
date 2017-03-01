<?php
namespace GuzzleHttp\Stream;

/**
 * Uses PHP's zlib.inflate filter to inflate deflate or gzipped content.
 *
 * This stream decorator skips the first 10 bytes of the given stream to remove
 * the gzip header, converts the provided stream to a PHP stream resource,
 * then appends the zlib.inflate filter. The stream is then converted back
 * to a Guzzle stream resource to be used as a Guzzle stream.
 *
 * @link http://tools.ietf.org/html/rfc1952
 * @link http://php.net/manual/en/filters.compression.php
 */
class InflateStream implements StreamInterface
{
    public function __construct(StreamInterface $stream)
    {
        // Skip the first 10 bytes
        $stream = new LimitStream($stream, -1, 10);
        $resource = GuzzleStreamWrapper::getResource($stream);
        stream_filter_append($resource, 'zlib.inflate', STREAM_FILTER_READ);
        $this->stream = new Stream($resource);
    }

    /** From trait */

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

	public function getSize()
	{
		return $this->stream->getSize();
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

	public function isWritable()
	{
		return $this->stream->isWritable();
	}

	public function isSeekable()
	{
		return $this->stream->isSeekable();
	}

	public function seek($offset, $whence = SEEK_SET)
	{
		return $this->stream->seek($offset, $whence);
	}

	public function read($length)
	{
		return $this->stream->read($length);
	}

	public function write($string)
	{
		return $this->stream->write($string);
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
