package main

import (
	"fmt"
	"os"
)

func main() {
	if len(os.Args) < 2 {
		printUsage()
		os.Exit(1)
	}

	command := os.Args[1]
	switch command {
	case "keygen":
		if err := runKeygen(); err != nil {
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			os.Exit(1)
		}
	case "sign":
		if err := runSign(); err != nil {
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			os.Exit(1)
		}
	case "pubkey":
		if err := runPubkey(); err != nil {
			fmt.Fprintf(os.Stderr, "Error: %v\n", err)
			os.Exit(1)
		}
	default:
		fmt.Fprintf(os.Stderr, "Unknown command: %s\n", command)
		printUsage()
		os.Exit(1)
	}
}

func printUsage() {
	fmt.Println("Usage: licensegen <command>")
	fmt.Println("Commands:")
	fmt.Println("  keygen          Generate Ed25519 keypair in keys/ directory")
	fmt.Println("  sign --machine  Sign a machine key and output license key")
	fmt.Println("  pubkey          Print the public key (base64)")
}
