#!/usr/bin/env swift

import Foundation
import CoreGraphics
import AppKit
import UniformTypeIdentifiers

struct CaptureError: Error, CustomStringConvertible {
    let message: String
    var description: String { message }
}

func captureWindow(windowId: CGWindowID, outputPath: String, cropTop: Int = 0) throws {
    guard let image = CGWindowListCreateImage(
        .null,
        .optionIncludingWindow,
        windowId,
        [.boundsIgnoreFraming, .nominalResolution]
    ) else {
        throw CaptureError(message: "Failed to capture window \(windowId). Window may not exist or be visible.")
    }
    
    var finalImage = image
    
    if cropTop > 0 {
        let width = image.width
        let height = image.height
        let cropHeight = min(cropTop * 2, height) // Retina displays have 2x pixels
        
        if let croppedImage = image.cropping(to: CGRect(
            x: 0,
            y: cropHeight,
            width: width,
            height: height - cropHeight
        )) {
            finalImage = croppedImage
        }
    }
    
    let url = URL(fileURLWithPath: outputPath)
    
    guard let destination = CGImageDestinationCreateWithURL(
        url as CFURL,
        UTType.png.identifier as CFString,
        1,
        nil
    ) else {
        throw CaptureError(message: "Failed to create image destination at \(outputPath)")
    }
    
    CGImageDestinationAddImage(destination, finalImage, nil)
    
    guard CGImageDestinationFinalize(destination) else {
        throw CaptureError(message: "Failed to write image to \(outputPath)")
    }
}

func findTerminalWindow(terminalName: String) -> CGWindowID? {
    let options = CGWindowListOption(arrayLiteral: .optionOnScreenOnly)
    guard let windowList = CGWindowListCopyWindowInfo(options, kCGNullWindowID) as NSArray? as? [[String: Any]] else {
        return nil
    }
    
    let normalizedName = terminalName.lowercased()
    let targetOwner: String
    
    switch normalizedName {
    case "iterm", "iterm2":
        targetOwner = "iterm2"
    case "ghostty":
        targetOwner = "ghostty"
    default:
        targetOwner = normalizedName
    }
    
    for window in windowList {
        guard let owner = window[kCGWindowOwnerName as String] as? String,
              let layer = window[kCGWindowLayer as String] as? Int,
              let windowId = window[kCGWindowNumber as String] as? Int,
              layer == 0 else {
            continue
        }
        
        if owner.lowercased() == targetOwner {
            return CGWindowID(windowId)
        }
    }
    
    return nil
}

func printUsage() {
    let usage = """
    Usage: capture-window.swift <command> [options]
    
    Commands:
      capture --window-id <id> --output <path> [--crop-top <pixels>]
          Capture a specific window by ID
      
      find-window --terminal <name>
          Find the frontmost window ID for a terminal (iterm, ghostty)
      
      capture-terminal --terminal <name> --output <path> [--crop-top <pixels>]
          Find and capture the frontmost terminal window
    
    Options:
      --window-id <id>     Window ID to capture (integer)
      --output <path>      Output file path (PNG)
      --crop-top <pixels>  Pixels to crop from top (for title bar removal)
      --terminal <name>    Terminal name: iterm, ghostty
    """
    print(usage)
}

func main() throws {
    let args = CommandLine.arguments
    
    guard args.count >= 2 else {
        printUsage()
        exit(1)
    }
    
    let command = args[1]
    
    switch command {
    case "capture":
        var windowId: CGWindowID?
        var outputPath: String?
        var cropTop = 0
        
        var i = 2
        while i < args.count {
            switch args[i] {
            case "--window-id":
                i += 1
                guard i < args.count, let id = UInt32(args[i]) else {
                    throw CaptureError(message: "Invalid or missing window ID")
                }
                windowId = CGWindowID(id)
            case "--output":
                i += 1
                guard i < args.count else {
                    throw CaptureError(message: "Missing output path")
                }
                outputPath = args[i]
            case "--crop-top":
                i += 1
                guard i < args.count, let pixels = Int(args[i]) else {
                    throw CaptureError(message: "Invalid crop-top value")
                }
                cropTop = pixels
            default:
                throw CaptureError(message: "Unknown option: \(args[i])")
            }
            i += 1
        }
        
        guard let wid = windowId else {
            throw CaptureError(message: "Window ID is required")
        }
        guard let path = outputPath else {
            throw CaptureError(message: "Output path is required")
        }
        
        try captureWindow(windowId: wid, outputPath: path, cropTop: cropTop)
        print("OK")
        
    case "find-window":
        var terminalName: String?
        
        var i = 2
        while i < args.count {
            switch args[i] {
            case "--terminal":
                i += 1
                guard i < args.count else {
                    throw CaptureError(message: "Missing terminal name")
                }
                terminalName = args[i]
            default:
                throw CaptureError(message: "Unknown option: \(args[i])")
            }
            i += 1
        }
        
        guard let name = terminalName else {
            throw CaptureError(message: "Terminal name is required")
        }
        
        guard let windowId = findTerminalWindow(terminalName: name) else {
            throw CaptureError(message: "Could not find window for terminal: \(name)")
        }
        
        print(windowId)
        
    case "capture-terminal":
        var terminalName: String?
        var outputPath: String?
        var cropTop = 0
        
        var i = 2
        while i < args.count {
            switch args[i] {
            case "--terminal":
                i += 1
                guard i < args.count else {
                    throw CaptureError(message: "Missing terminal name")
                }
                terminalName = args[i]
            case "--output":
                i += 1
                guard i < args.count else {
                    throw CaptureError(message: "Missing output path")
                }
                outputPath = args[i]
            case "--crop-top":
                i += 1
                guard i < args.count, let pixels = Int(args[i]) else {
                    throw CaptureError(message: "Invalid crop-top value")
                }
                cropTop = pixels
            default:
                throw CaptureError(message: "Unknown option: \(args[i])")
            }
            i += 1
        }
        
        guard let name = terminalName else {
            throw CaptureError(message: "Terminal name is required")
        }
        guard let path = outputPath else {
            throw CaptureError(message: "Output path is required")
        }
        
        guard let windowId = findTerminalWindow(terminalName: name) else {
            throw CaptureError(message: "Could not find window for terminal: \(name)")
        }
        
        try captureWindow(windowId: windowId, outputPath: path, cropTop: cropTop)
        print("OK:\(windowId)")
        
    case "--help", "-h":
        printUsage()
        
    default:
        printUsage()
        exit(1)
    }
}

do {
    try main()
} catch {
    fputs("Error: \(error)\n", stderr)
    exit(1)
}
