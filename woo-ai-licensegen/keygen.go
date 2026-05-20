package main

import (
	"crypto/ed25519"
	"encoding/base64"
	"fmt"
	"os"
	"path/filepath"
)

func runKeygen() error {
	keysDir := "keys"
	if err := os.MkdirAll(keysDir, 0700); err != nil {
		return fmt.Errorf("create keys dir: %w", err)
	}

	pubKey, privKey, err := ed25519.GenerateKey(nil)
	if err != nil {
		return fmt.Errorf("generate key: %w", err)
	}

	pubFile := filepath.Join(keysDir, "public.key")
	privFile := filepath.Join(keysDir, "private.key")

	if err := os.WriteFile(pubFile, pubKey, 0644); err != nil {
		return fmt.Errorf("write public key: %w", err)
	}
	if err := os.WriteFile(privFile, privKey, 0600); err != nil {
		return fmt.Errorf("write private key: %w", err)
	}

	fmt.Println("Keypair generated successfully.")
	fmt.Printf("Public key:  %s\n", base64.StdEncoding.EncodeToString(pubKey))
	fmt.Printf("Private key: %s\n", base64.StdEncoding.EncodeToString(privKey))
	fmt.Printf("Files: %s, %s\n", pubFile, privFile)

	return nil
}

func loadPrivateKey() (ed25519.PrivateKey, error) {
	data, err := os.ReadFile("keys/private.key")
	if err != nil {
		return nil, fmt.Errorf("read private key: %w (run 'licensegen keygen' first)", err)
	}
	return ed25519.PrivateKey(data), nil
}

func loadPublicKey() (ed25519.PublicKey, error) {
	data, err := os.ReadFile("keys/public.key")
	if err != nil {
		return nil, fmt.Errorf("read public key: %w (run 'licensegen keygen' first)", err)
	}
	return ed25519.PublicKey(data), nil
}

func runPubkey() error {
	pubKey, err := loadPublicKey()
	if err != nil {
		return err
	}
	fmt.Println(base64.StdEncoding.EncodeToString(pubKey))
	return nil
}
