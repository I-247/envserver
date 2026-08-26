// Package vault keeps a release on disk in a form that is useless without the
// secret it was sealed with.
//
// It exists for the middle ground between the two things the CLI could
// already do: a plaintext .env that anyone reading the disk can read, and a
// live fetch that needs the network and a valid token every single time. A
// sealed file survives a reboot, a flight and an unreachable server, and still
// reads as noise to anything that does not hold the key.
package vault

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hkdf"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
)

// FileName is the sealed file, written next to kluis.json.
const FileName = ".env.kluis"

const (
	filePrefix    = "kluis-vault"
	formatVersion = "v1"

	keyLength  = 32
	saltLength = 16
	idLength   = 8

	// Separate labels for the two things derived from one secret: the key
	// that opens the file, and the fingerprint that is written into it.
	// Without them the stored id would be a slice of the key itself.
	keyLabel = "kluis vault key v1"
	idLabel  = "kluis vault key id v1"
)

// ErrWrongKey means the file was sealed with a different secret. Recognising
// this before attempting to decrypt turns an unexplained failure into an
// instruction: re-seal, or find the token this was sealed with.
var ErrWrongKey = errors.New("this vault was sealed with a different key")

// ErrCorrupt means the file is not a vault, or no longer intact.
var ErrCorrupt = errors.New("this is not a readable kluis vault file")

// Payload is everything a sealed file holds. All of it is encrypted,
// including which project and environment it came from: a file that announces
// "production, webshop" is already telling someone where to aim.
type Payload struct {
	Release     int               `json:"release"`
	Project     string            `json:"project"`
	Environment string            `json:"environment"`
	SealedAt    string            `json:"sealed_at"`
	Variables   map[string]string `json:"variables"`
}

// Secret is the material a vault key is derived from.
//
// It is never the key itself. Everything goes through HKDF, so the caller may
// hand over a deploy token secret, a generated hex string or a long
// passphrase without the file format caring which.
type Secret struct {
	material []byte
	label    string
}

// FromDeployToken derives the vault secret from the credentials a deploy
// server already holds, which means a sealed file needs no second secret to
// manage: whoever may pull the release may open the file, and nobody else.
//
// The client id becomes the HKDF label so the file key is domain separated
// from the OAuth credential it shares its input with.
func FromDeployToken(clientID, clientSecret string) Secret {
	return Secret{material: []byte(clientSecret), label: "deploy-token:" + clientID}
}

// FromKey accepts an explicit secret: hex, base64 or plain text. Anything
// shorter than 16 bytes is refused, because HKDF happily stretches a weak
// secret into a key that looks every bit as strong as a good one.
func FromKey(value string) (Secret, error) {
	value = strings.TrimSpace(value)

	material := decoded(value)
	if len(material) < 16 {
		return Secret{}, errors.New("a vault key needs at least 16 bytes; try: openssl rand -hex 32")
	}

	return Secret{material: material, label: "key"}, nil
}

// decoded unwraps hex or base64 when the value is exactly that, and otherwise
// takes the bytes as they are.
func decoded(value string) []byte {
	if raw, err := hex.DecodeString(value); err == nil && len(raw) >= 16 {
		return raw
	}

	if raw, err := base64.StdEncoding.DecodeString(value); err == nil && len(raw) >= 16 {
		return raw
	}

	return []byte(value)
}

// derive turns the secret into the file key and its fingerprint.
func (s Secret) derive(salt []byte) (key []byte, id string, err error) {
	key, err = hkdf.Key(sha256.New, s.material, salt, keyLabel+":"+s.label, keyLength)
	if err != nil {
		return nil, "", err
	}

	fingerprint, err := hkdf.Key(sha256.New, s.material, salt, idLabel+":"+s.label, idLength)
	if err != nil {
		return nil, "", err
	}

	return key, hex.EncodeToString(fingerprint), nil
}

// header holds what is needed to derive the key again, and nothing that helps
// anyone who does not have the secret.
type header struct {
	KDF   string `json:"kdf"`
	Salt  string `json:"salt"`
	KeyID string `json:"key_id"`
}

// Seal encrypts the payload into the file format.
//
// The layout mirrors the server side ciphertexts: a name and a version up
// front, so a future scheme can live next to this one instead of having to be
// guessed at from the bytes.
//
//	kluis-vault.v1.<header>.<nonce>.<ciphertext>
func Seal(payload Payload, secret Secret) (string, error) {
	salt := make([]byte, saltLength)
	if _, err := rand.Read(salt); err != nil {
		return "", err
	}

	key, id, err := secret.derive(salt)
	if err != nil {
		return "", err
	}

	plaintext, err := json.Marshal(payload)
	if err != nil {
		return "", err
	}

	meta, err := json.Marshal(header{
		KDF:   "hkdf-sha256",
		Salt:  encode(salt),
		KeyID: id,
	})
	if err != nil {
		return "", err
	}

	gcm, err := cipherFor(key)
	if err != nil {
		return "", err
	}

	nonce := make([]byte, gcm.NonceSize())
	if _, err := rand.Read(nonce); err != nil {
		return "", err
	}

	// The header is authenticated as additional data. Swapping the salt for
	// one belonging to another file would otherwise be a silent edit.
	encodedMeta := encode(meta)
	ciphertext := gcm.Seal(nil, nonce, plaintext, []byte(encodedMeta))

	return strings.Join([]string{
		filePrefix, formatVersion, encodedMeta, encode(nonce), encode(ciphertext),
	}, ".") + "\n", nil
}

// Open decrypts a sealed file.
func Open(contents string, secret Secret) (*Payload, error) {
	meta, encodedMeta, nonce, ciphertext, err := split(contents)
	if err != nil {
		return nil, err
	}

	salt, err := base64.RawStdEncoding.DecodeString(meta.Salt)
	if err != nil {
		return nil, ErrCorrupt
	}

	key, id, err := secret.derive(salt)
	if err != nil {
		return nil, err
	}

	if meta.KeyID != "" && meta.KeyID != id {
		return nil, ErrWrongKey
	}

	gcm, err := cipherFor(key)
	if err != nil {
		return nil, err
	}

	if len(nonce) != gcm.NonceSize() {
		return nil, ErrCorrupt
	}

	plaintext, err := gcm.Open(nil, nonce, ciphertext, []byte(encodedMeta))
	if err != nil {
		// A correct key id and a failing tag means the bytes were changed,
		// not that the wrong key was used.
		return nil, fmt.Errorf("%w: the contents were altered after sealing", ErrCorrupt)
	}

	payload := &Payload{}
	if err := json.Unmarshal(plaintext, payload); err != nil {
		return nil, ErrCorrupt
	}

	return payload, nil
}

// KeyID reads the fingerprint of the secret a file was sealed with, without
// needing that secret. Enough to tell two vaults apart in a message.
func KeyID(contents string) (string, error) {
	meta, _, _, _, err := split(contents)
	if err != nil {
		return "", err
	}

	return meta.KeyID, nil
}

func split(contents string) (meta header, encodedMeta string, nonce, ciphertext []byte, err error) {
	parts := strings.Split(strings.TrimSpace(contents), ".")
	if len(parts) != 5 || parts[0] != filePrefix {
		return meta, "", nil, nil, ErrCorrupt
	}

	if parts[1] != formatVersion {
		return meta, "", nil, nil, fmt.Errorf(
			"this vault is format %s and this kluis only reads %s; upgrade the CLI", parts[1], formatVersion)
	}

	rawMeta, err := base64.RawStdEncoding.DecodeString(parts[2])
	if err != nil {
		return meta, "", nil, nil, ErrCorrupt
	}

	if err := json.Unmarshal(rawMeta, &meta); err != nil {
		return meta, "", nil, nil, ErrCorrupt
	}

	if nonce, err = base64.RawStdEncoding.DecodeString(parts[3]); err != nil {
		return meta, "", nil, nil, ErrCorrupt
	}

	if ciphertext, err = base64.RawStdEncoding.DecodeString(parts[4]); err != nil {
		return meta, "", nil, nil, ErrCorrupt
	}

	return meta, parts[2], nonce, ciphertext, nil
}

func cipherFor(key []byte) (cipher.AEAD, error) {
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}

	return cipher.NewGCM(block)
}

func encode(value []byte) string {
	return base64.RawStdEncoding.EncodeToString(value)
}
