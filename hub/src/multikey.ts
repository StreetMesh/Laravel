/**
 * Reading a key the way a DID document writes one.
 *
 * A verification method publishes `publicKeyMultibase` — a string that says
 * which curve it is and then the key, in one token. This turns that back into
 * something WebCrypto will verify with.
 *
 * Only P-256 is read here, and deliberately so. It is what this project mints,
 * it is what `did:plc` permits alongside secp256k1, and it is the one the
 * platform can verify without a dependency. A document naming another curve is
 * refused rather than guessed at.
 */

const BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'

/** The multicodec prefix for a P-256 public key, varint-encoded. */
const P256_PREFIX = [0x80, 0x24]

/**
 * The prime the curve is defined over, and the parameters needed to recover a
 * y coordinate from an x. A multikey holds the *compressed* point — x, plus one
 * bit saying whether y is odd — so verifying means solving the curve equation
 * for the y that was left out.
 */
const P = 2n ** 256n - 2n ** 224n + 2n ** 192n + 2n ** 96n - 1n
const B = 0x5ac635d8aa3a93e7b3ebbd55769886bc651d06b0cc53b0f63bce3c3e27d2604bn

function decodeBase58(input: string): Uint8Array {
  let value = 0n

  for (const character of input) {
    const digit = BASE58.indexOf(character)

    if (digit === -1) {
      throw new Error(`[${input}] is not base58btc.`)
    }

    value = value * 58n + BigInt(digit)
  }

  const bytes: number[] = []

  while (value > 0n) {
    bytes.unshift(Number(value & 0xffn))
    value >>= 8n
  }

  // Leading zero bytes encode as leading '1's and are lost by the arithmetic.
  for (const character of input) {
    if (character !== '1') break
    bytes.unshift(0)
  }

  return Uint8Array.from(bytes)
}

/**
 * y² = x³ - 3x + b, solved for y.
 *
 * The square root exists because p ≡ 3 (mod 4), which makes it a single
 * exponentiation rather than anything iterative.
 */
function recoverY(x: bigint, odd: boolean): bigint {
  const ySquared = (x ** 3n - 3n * x + B) % P
  let y = modPow(ySquared, (P + 1n) / 4n, P)

  if ((y % 2n === 1n) !== odd) {
    y = P - y
  }

  if ((y * y - ySquared) % P !== 0n) {
    throw new Error('That key is not a point on P-256.')
  }

  return y
}

function modPow(base: bigint, exponent: bigint, modulus: bigint): bigint {
  let result = 1n
  let b = base % modulus

  for (let e = exponent; e > 0n; e >>= 1n) {
    if (e & 1n) result = (result * b) % modulus
    b = (b * b) % modulus
  }

  return result
}

function toBase64Url(value: bigint): string {
  const hex = value.toString(16).padStart(64, '0')
  const bytes = Uint8Array.from(hex.match(/../g)!.map((pair) => parseInt(pair, 16)))

  return Buffer.from(bytes).toString('base64url')
}

/**
 * A published key, as something that can check a signature.
 */
export async function importMultikey(multikey: string): Promise<CryptoKey> {
  if (!multikey.startsWith('z')) {
    throw new Error('A multikey is base58btc, which starts with z.')
  }

  const decoded = decodeBase58(multikey.slice(1))

  if (decoded[0] !== P256_PREFIX[0] || decoded[1] !== P256_PREFIX[1]) {
    throw new Error('That key is not on P-256, which is the only curve read here.')
  }

  const point = decoded.slice(2)

  if (point.length !== 33) {
    throw new Error('A compressed P-256 point is 33 bytes.')
  }

  const x = BigInt('0x' + Buffer.from(point.slice(1)).toString('hex'))
  const y = recoverY(x, point[0] === 3)

  return crypto.subtle.importKey(
    'jwk',
    { kty: 'EC', crv: 'P-256', x: toBase64Url(x), y: toBase64Url(y) },
    { name: 'ECDSA', namedCurve: 'P-256' },
    false,
    ['verify'],
  )
}
