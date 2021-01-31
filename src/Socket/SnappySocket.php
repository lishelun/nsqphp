<?php

declare(strict_types=1);

namespace Nsq\Socket;

use PHPinnacle\Buffer\ByteBuffer;
use Psr\Log\LoggerInterface;
use function hash;
use function pack;
use function snappy_compress;
use function snappy_uncompress;
use function str_split;
use function unpack;

final class SnappySocket implements Socket
{
    private ByteBuffer $output;

    private ByteBuffer $input;

    public function __construct(
        private Socket $socket,
        private LoggerInterface $logger,
    ) {
        $this->output = new ByteBuffer();
        $this->input = new ByteBuffer();
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $data): void
    {
        $identifierFrame = [0xff, 0x06, 0x00, 0x00, 0x73, 0x4e, 0x61, 0x50, 0x70, 0x59];
        $compressedFrame = 0x00;
        $uncompressedFrame = 0x01; // 11
        $maxChunkLength = 65536;

        $byteBuffer = new ByteBuffer();
        foreach ($identifierFrame as $bite) {
            $byteBuffer->appendUint8($bite);
        }

        foreach (str_split($data, $maxChunkLength) as $chunk) {
            $compressedChunk = snappy_compress($chunk);

            [$chunk, $chunkType] = \strlen($compressedChunk) <= 0.875 * \strlen($data)
                ? [$compressedChunk, $compressedFrame]
                : [$data, $uncompressedFrame];

            /** @var string $checksum */
            $checksum = hash('crc32c', $data, true);
            /** @phpstan-ignore-next-line  */
            $checksum = unpack('N', $checksum)[1];
            $maskedChecksum = (($checksum >> 15) | ($checksum << 17)) + 0xa282ead8 & 0xffffffff;

            $size = (\strlen($chunk) + 4) << 8;

            $byteBuffer->append(pack('V', $chunkType + $size));
            $byteBuffer->append(pack('V', $maskedChecksum));
            $byteBuffer->append($chunk);
        }

        $this->socket->write($byteBuffer->flush());
    }

    /**
     * {@inheritDoc}
     */
    public function read(int $length): string
    {
        $output = $this->output;
        $input = $this->input;

        $this->logger->debug('Snappy requested {length} bytes.', ['length' => $length]);

        while ($output->size() < $length) {
            $this->logger->debug('Snappy enter loop');

            /** @phpstan-ignore-next-line  */
            $chunkType = unpack('V', $this->socket->read(4))[1];

            $size = $chunkType >> 8;
            $chunkType &= 0xff;

            $this->logger->debug('Snappy receive chunk [{chunk}] with size [{size}]', [
                'chunk' => $chunkType,
                'size' => $size,
            ]);

            switch ($chunkType) {
                case 0xff:
                    $this->logger->debug('Snappy identifier chunk');

                    $this->socket->read(6); // discard identifier body

                    break;
                case 0x00: // 'compressed',
                    $this->logger->debug('Snappy compressed chunk');

                    $input->append(
                        $this->socket->read($size),
                    )
                        ->discard(4) // discard checksum
                    ;

                    $output->append(
                        snappy_uncompress(
                            $input->flush(),
                        ),
                    );

                    break;
                case 0x01: // 'uncompressed',
                    $this->logger->debug('Snappy uncompressed chunk');

                    $input->append(
                        $this->socket->read($size),
                    )
                        ->discard(4) // discard checksum
                    ;

                    $output->append($input->flush());

                    break;
                case 0xfe:// 'padding',
                    $this->logger->debug('Snappy padding chunk');

                    break;
            }
        }

        $this->logger->debug('Snappy return message [{message}]', ['message' => $output->read($length)]);

        return $output->consume($length);
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        $this->socket->close();
    }

    /**
     * {@inheritDoc}
     */
    public function selectRead(float $timeout): bool
    {
        return !$this->input->empty() || $this->socket->selectRead($timeout);
    }
}
