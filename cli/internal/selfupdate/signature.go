package selfupdate

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"regexp"

	"github.com/sigstore/sigstore-go/pkg/bundle"
	"github.com/sigstore/sigstore-go/pkg/root"
	"github.com/sigstore/sigstore-go/pkg/verify"
)

// SignatureAsset is the Sigstore bundle the release workflow publishes next
// to checksums.txt (see cli/.goreleaser.yaml's signs section).
const SignatureAsset = "checksums.txt.sigstore.json"

// Issuer is the OIDC issuer every release signature must come from: GitHub
// Actions, where the release workflow runs.
const Issuer = "https://token.actions.githubusercontent.com"

// ErrUnsigned is returned when a release carries no signature at all.
var ErrUnsigned = errors.New("this release is not signed")

// Identity is the certificate identity a release for tag must be signed
// with: this repository's release workflow, running for exactly that tag.
//
// Anchored at both ends and built from the tag being installed, so neither
// a fork's workflow, another workflow in this repository, a branch run, nor
// a signature from a different tag is accepted.
func Identity(tag string) string {
	return "^" + regexp.QuoteMeta("https://github.com/"+Repo+"/.github/workflows/release.yml@refs/tags/"+tag) + "$"
}

// Verifier checks that checksums.txt was signed for tag by the release
// workflow.
type Verifier interface {
	Verify(ctx context.Context, checksums, signature []byte, tag string) error
}

// SigstoreVerifier verifies against the public Sigstore instance.
//
// Trusted is the root of trust; nil fetches Sigstore's trusted root over
// TUF, which is itself verified against the root embedded in sigstore-go.
type SigstoreVerifier struct {
	Trusted root.TrustedMaterial
}

// Verify parses the bundle and applies the release policy to it.
func (v SigstoreVerifier) Verify(_ context.Context, checksums, signature []byte, tag string) error {
	if len(signature) == 0 {
		return ErrUnsigned
	}

	parsed := &bundle.Bundle{}
	if err := parsed.UnmarshalJSON(signature); err != nil {
		return fmt.Errorf("the release signature cannot be read: %w", err)
	}

	trusted := v.Trusted
	if trusted == nil {
		fetched, err := root.FetchTrustedRoot()
		if err != nil {
			return fmt.Errorf("cannot fetch the Sigstore trusted root to check the release signature: %w", err)
		}

		trusted = fetched
	}

	return verifyEntity(trusted, parsed, checksums, tag)
}

// verifyEntity is the policy itself: a certificate from the release
// workflow for this tag, logged in the transparency log, over exactly these
// checksums.
func verifyEntity(trusted root.TrustedMaterial, entity verify.SignedEntity, checksums []byte, tag string) error {
	verifier, err := verify.NewVerifier(
		trusted,
		verify.WithTransparencyLog(1),
		verify.WithObserverTimestamps(1),
	)
	if err != nil {
		return err
	}

	identity, err := verify.NewShortCertificateIdentity(Issuer, "", "", Identity(tag))
	if err != nil {
		return err
	}

	policy := verify.NewPolicy(
		verify.WithArtifact(bytes.NewReader(checksums)),
		verify.WithCertificateIdentity(identity),
	)

	if _, err := verifier.Verify(entity, policy); err != nil {
		return fmt.Errorf("the release signature does not verify: %w", err)
	}

	return nil
}
