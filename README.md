# TCP File Server

A multi-client TCP server with file transfer capabilities.

## Project Structure
- `server.php` - Main server application
- `client.php` - Client application  
- `config.php` - Server configuration
- `file_utils.php` - File operation utilities
- `logger.php` - Logging system
- `server_files/` - Directory for uploaded files

## Setup
1. Run server: `php server.php`
2. Run client: `php client.php 127.0.0.1 9000 username`

## Commands
### Basic Commands (All Users)
- `/auth username [password]` - Authenticate user
- `STATS` - View server statistics

### Admin Commands
- `/list` - List all files
- `/upload filename` - Upload a file
- `/download filename` - Download a file
- `/read filename` - View file content
- `/delete filename` - Delete a file
- `/search keyword` - Search files
- `/info filename` - Get file information