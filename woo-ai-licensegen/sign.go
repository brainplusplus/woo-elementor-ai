package main

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"fmt"
	"os"
	"strings"
)

func runSign() error {
	var machineKey string
	args := os.Args[2:]

	for i := 0; i < len(args); i++ {
		if args[i] == "--machine" && i+1 < len(args) {
			machineKey = args[i+1]
			break
		}
		if strings.HasPrefix(args[i], "--machine=") {
			machineKey = strings.TrimPrefix(args[i], "--machine=")
			machineKey = strings.Trim(machineKey, "\"'")
			break
		}
		if strings.HasPrefix(args[i], "--machine") && i+1 < len(args) {
			machineKey = args[i+1]
			break
		}
	}

	if machineKey == "" {
		return fmt.Errorf("--machine flag is required. Usage: licensegen sign --machine=<key>")
	}

	privKey, err := loadPrivateKey()
	if err != nil {
		return err
	}

	hash := sha256.Sum256([]byte(machineKey))
	machineKeyHash := fmt.Sprintf("%x", hash)[:16]

	signature := ed25519.Sign(privKey, []byte(machineKeyHash))

	payload := machineKeyHash + "|" + string(signature)
	licenseKey := base64.StdEncoding.EncodeToString([]byte(payload))

	fmt.Println(licenseKey)

	return nil
}
