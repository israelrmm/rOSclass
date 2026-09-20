<?php
/*****************************
 * RouterOS PHP API class (sep 2026)
 *
 * REQUIERE PHP 8.2 O SUPERIOR
 *
 * Based in work of https://github.com/BenMenking/routeros-api
 * by Israel Marrero
 *
 * Optimizaciones de riesgo bajo aplicadas:
 *   #1  readBytes() con puntero de offset (evita substr() O(n²))
 *   #3  parseResponse() sin referencias
 *   #4  Contexto de stream cacheado + tcp_nodelay vía context
 *   #5  Sin stream_get_meta_data() en el bucle caliente
 *   #6  encodeLength() con pack() en lugar de chr() encadenados
 *   #8  Pipelining: send() / receive()
 *   #9  Bundle de escrituras en send() → 1 fwrite por comando
 *   #10 stream_set_chunk_size() + read/write buffer desactivados
 *   #11 Lectura agrupada de bytes de longitud (2/3/4 en una llamada)
 *   #12 parseResponse() con explode(..., 2)
 *
 * La API pública (connect, disconnect, write, read, comm, execmd,
 * parseResponse, encodeLength) mantiene firma y comportamiento original.
 * Métodos nuevos: send() y receive() para pipelining opcional.
 ***********************************************/

class RouterosAPI
{
    // Propiedades con tipos definidos
    public bool $debug      = false;
    public bool $connected  = false;
    public int  $port       = 8728;
    public bool $ssl        = false;
    public int  $timeout    = 225;
    public int  $attempts   = 3;
    public int  $delay      = 2;

    /** Tamaño del bloque de lectura del socket (bytes). */
    public int $readChunkSize = 65536;

    /** @var resource|null */
    protected $socket;

    /** Buffer interno para evitar fread() byte a byte. */
    protected string $readBuffer = '';

    /** Posición de lectura dentro de $readBuffer (evita substr() O(n) por lectura). */
    protected int $readPos = 0;

    /** @var resource|null Contexto de stream cacheado. */
    protected $context = null;

    public $error_no;
    public $error_str;

    /**
     * Imprime mensajes de depuración en consola.
     */
    protected function debug(string $text): void
    {
        if ($this->debug) {
            echo "[RouterOS API] " . $text . PHP_EOL;
        }
    }

    /**
     * Codifica la longitud de la cadena según el protocolo de MikroTik.
     * Usa pack() en lugar de chr() encadenados (menos llamadas a función,
     * mismos bytes en el cable).
     */
    public function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        } elseif ($length < 0x4000) {
            return pack('n', $length | 0x8000);
        } elseif ($length < 0x200000) {
            return substr(pack('N', $length | 0xC00000), 1);
        } elseif ($length < 0x10000000) {
            return pack('N', $length | 0xE0000000);
        }
        return "\xF0" . pack('N', $length);
    }

    /* ---------------------------------------------------------------------
     *  Buffer de lectura con offset
     * ------------------------------------------------------------------- */

    /**
     * Devuelve exactamente $n bytes (o menos si el socket cierra/timeout).
     * Lee del socket en bloques grandes y mantiene un buffer interno con
     * un puntero de posición para evitar copias O(n) en cada lectura.
     */
    protected function readBytes(int $n): string
    {
        if ($n <= 0) return '';

        $available = strlen($this->readBuffer) - $this->readPos;

        while ($available < $n) {
            $need  = $n - $available;
            $chunk = fread($this->socket, max($this->readChunkSize, $need));
            if ($chunk === false || $chunk === '') {
                break; // timeout o desconexión
            }
            $this->readBuffer .= $chunk;
            $available += strlen($chunk);
        }

        if ($available === 0) return '';

        $take = $available < $n ? $available : $n;
        $data = substr($this->readBuffer, $this->readPos, $take);
        $this->readPos += $take;

        // Compactar el buffer sólo cuando ya hemos consumido bastante
        // o cuando lo hemos vaciado por completo.
        if ($this->readPos >= 65536 || $this->readPos >= strlen($this->readBuffer)) {
            $this->readBuffer = substr($this->readBuffer, $this->readPos);
            $this->readPos    = 0;
        }

        return $data;
    }

    /* ---------------------------------------------------------------------
     *  Contexto de stream cacheado
     * ------------------------------------------------------------------- */

    /**
     * Devuelve (y cachea) el contexto de stream usado para la conexión.
     * 'socket' => ['tcp_nodelay' => true] está disponible desde PHP 7.1.
     */
    protected function getContext()
    {
        if ($this->context === null) {
            $this->context = stream_context_create([
                'socket' => [
                    'tcp_nodelay' => true,
                ],
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ]);
        }
        return $this->context;
    }

    /* ---------------------------------------------------------------------
     *  Conexión
     * ------------------------------------------------------------------- */
    public function connect(string $ip, string $login, string $password): bool
    {
        for ($a = 1; $a <= $this->attempts; $a++) {
            $this->connected  = false;
            $this->readBuffer = '';
            $this->readPos    = 0;

            $protocol = ($this->ssl ? 'ssl://' : 'tcp://');

            $this->socket = @stream_socket_client(
                $protocol . $ip . ':' . $this->port,
                $this->error_no,
                $this->error_str,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $this->getContext()
            );

            if (is_resource($this->socket)) {
                stream_set_timeout($this->socket, $this->timeout);

                // Permitir reads grandes y desactivar el buffering interno de PHP.
                stream_set_chunk_size($this->socket, $this->readChunkSize);
                stream_set_read_buffer($this->socket, 0);
                stream_set_write_buffer($this->socket, 0);

                if ($this->loginProcess($login, $password)) {
                    $this->connected = true;
                    $this->debug("Connected to $ip");
                    return true;
                }

                fclose($this->socket);
            }

            if ($a < $this->attempts) {
                sleep($this->delay);
            }
        }

        $this->debug("Failed to connect to $ip");
        return false;
    }

    /**
     * Maneja el proceso de autenticación (compatible con v6.43+ y versiones antiguas).
     */
    private function loginProcess(string $login, string $password): bool
    {
        $this->write('/login', false);
        $this->write('=name=' . $login, false);
        $this->write('=password=' . $password);

        $response = $this->read(false);

        if (($response[0] ?? null) === '!done') {
            if (!isset($response[1])) {
                return true;
            }
            if (preg_match('/ret=([0-9a-f]{32})/', $response[1], $matches)) {
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=response=00' . md5(chr(0) . $password . pack('H*', $matches[1])));
                $response = $this->read(false);
                return (($response[0] ?? null) === '!done');
            }
        }
        return false;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->connected  = false;
        $this->readBuffer = '';
        $this->readPos    = 0;
    }

    /* ---------------------------------------------------------------------
     *  Escritura (API pública legacy)
     * ------------------------------------------------------------------- */

    /**
     * Escribe longitud + palabra (+ terminador si $terminal).
     * Se hace todo en un solo fwrite → 1 syscall en lugar de 2.
     *
     * Se mantiene por compatibilidad con código existente y porque
     * loginProcess() lo usa. Para envíos batcheados usar send().
     */
    public function write(string $command, bool $terminal = true): bool
    {
        if (!is_resource($this->socket)) return false;

        $command = trim($command);
        $data = $this->encodeLength(strlen($command)) . $command;
        if ($terminal) {
            $data .= chr(0);
        }

        fwrite($this->socket, $data);
        $this->debug("<<< $command");
        return true;
    }

    /* ---------------------------------------------------------------------
     *  Lectura
     * ------------------------------------------------------------------- */

    /**
     * Lee la respuesta del RouterOS.
     */
    public function read(bool $parse = true)
    {
        $responses = [];

        while (is_resource($this->socket)) {
            $byteStr = $this->readBytes(1);
            if ($byteStr === '') break;

            $byte = ord($byteStr);

            // Decodificar longitud (protocolo MikroTik).
            // Los bytes de longitud se leen agrupados para reducir
            // llamadas a readBytes() por palabra.
            if ($byte & 128) {
                if (($byte & 192) === 128) {
                    $length = (($byte & 63) << 8) | ord($this->readBytes(1));
                } elseif (($byte & 224) === 192) {
                    $b = $this->readBytes(2);
                    $length = (($byte & 31) << 16) | (ord($b[0]) << 8) | ord($b[1]);
                } elseif (($byte & 240) === 224) {
                    $b = $this->readBytes(3);
                    $length = (($byte & 15) << 24)
                            | (ord($b[0]) << 16)
                            | (ord($b[1]) << 8)
                            |  ord($b[2]);
                } else {
                    $b = $this->readBytes(4);
                    $length = (ord($b[0]) << 24)
                            | (ord($b[1]) << 16)
                            | (ord($b[2]) << 8)
                            |  ord($b[3]);
                }
            } else {
                $length = $byte;
            }

            $chunk = $length > 0 ? $this->readBytes($length) : '';
            $responses[] = $chunk;

            if ($this->debug) {
                $this->debug(">>> $chunk");
            }

            // Fin de respuesta
            if ($chunk === '!done') break;

            // Nota: si el socket hace timeout, readBytes() devuelve '' y
            // la siguiente iteración rompe el bucle por la misma vía.
            // No es necesario consultar stream_get_meta_data() aquí.
        }

        return $parse ? $this->parseResponse($responses) : $responses;
    }

    /**
     * Convierte la respuesta plana en un array asociativo.
     * Sin referencias: menos refcounts y copias en respuestas grandes.
     */
    public function parseResponse(array $response): array
    {
        $parsed = [];
        $kind   = null;   // 'list' | '!trap' | '!fatal'
        $idx    = -1;

        foreach ($response as $line) {
            if ($line === '') continue;

            if ($line === '!re') {
                $parsed[] = [];
                $kind = 'list';
                $idx  = count($parsed) - 1;
            } elseif ($line === '!trap' || $line === '!fatal') {
                if (!isset($parsed[$line])) {
                    $parsed[$line] = [];
                }
                $parsed[$line][] = [];
                $kind = $line;
                $idx  = count($parsed[$line]) - 1;
            } elseif ($line[0] === '=' && $idx >= 0) {
                $parts = explode('=', substr($line, 1), 2);
                $k = $parts[0];
                $v = $parts[1] ?? '';
                if ($kind === 'list') {
                    $parsed[$idx][$k] = $v;
                } else {
                    $parsed[$kind][$idx][$k] = $v;
                }
            }
        }
        return $parsed;
    }

    /**
     * Ejecuta comandos enviando un string completo (ej: "/ip/address/print").
     */
    public function execmd(string $command): array
    {
        if ($command === '') return [];

        $data  = explode(' ', trim($command));
        $count = count($data);

        foreach ($data as $i => $com) {
            if ($com === '') continue;
            $last   = ($i === $count - 1);
            $prefix = '';
            if ($i > 0) {
                $fc = $com[0];
                if ($fc !== '~' && $fc !== '?') {
                    $prefix = '=';
                }
            }
            $this->write($prefix . $com, $last);
        }
        return $this->read();
    }

    /* ---------------------------------------------------------------------
     *  Envío / recepción separados (pipelining)
     * ------------------------------------------------------------------- */

    /**
     * Envía un comando SIN leer la respuesta.
     *
     * Todas las escrituras (comando + parámetros + terminador) se agrupan
     * en un único fwrite → 1 syscall por comando completo, sin importar
     * cuántos parámetros tenga.
     *
     * Combínalo con receive() para hacer pipelining de varios comandos
     * y ahorrar round-trips.
     */
    public function send(string $com, array $arr = []): void
    {
        if (!is_resource($this->socket)) return;

        $com = trim($com);
        $buf = $this->encodeLength(strlen($com)) . $com;

        if (empty($arr)) {
            $buf .= "\0";
        } else {
            $count = count($arr);
            $i     = 0;
            foreach ($arr as $k => $v) {
                $first  = $k !== '' ? $k[0] : '';
                $prefix = ($first === '?' || $first === '~') ? '' : '=';
                $line   = trim($prefix . $k . '=' . $v);
                $buf   .= $this->encodeLength(strlen($line)) . $line;
                if (++$i === $count) {
                    $buf .= "\0";
                }
            }
        }

        fwrite($this->socket, $buf);
        $this->debug("<<< $com");
    }

    /**
     * Lee la siguiente respuesta pendiente enviada con send().
     */
    public function receive(): array
    {
        return $this->read();
    }

    /**
     * Método preferido para enviar comandos con arrays de parámetros.
     * Se compone de send() + receive().
     */
    public function comm(string $com, array $arr = []): array
    {
        $this->send($com, $arr);
        return $this->receive();
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
