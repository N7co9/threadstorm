# Threadstorm CLI

## Overview

**Threadstorm CLI** is a command-line tool designed to interact with the Threads API. It allows users to automate post creation, manage replies, audit interactions, and configure various aspects of their posting behavior.

## Features

- 📜 **List threads**: Retrieve all existing threads
- 📡 **Post threads**: Create a new thread
- 🔌 **Check API status**: Verify the API connection
- 🔎 **Retrieve threads**: Get details of specific threads
- 🛑 **Delete threads**: Remove a thread (NOT functional, since not supported by Meta's API as of 02/25)
- 🤖 **Auto-Posting**: Automate thread creation
- 🤖 **Auto-Reply**: Automate responses to threads
- ⚙️ **Configuration Management**: Adjust parameters for auto-posting
- ✍️ **Audit Mode**: Review the performance of auto-posting and auto-reply

## Installation

Clone the repository and install dependencies:

```sh
git clone https://github.com/yourusername/threadstorm-cli.git
cd threadstorm-cli
composer install
```

## Requirements

⚠️ Be warned: You will most likely not be able to clone and run this project to your satisfication;
You will most definetly need to adjust the prompts to match your intentions. 
Prompts are being provided by the PromptProvider, which is situated here **'/src/Service/Extension/AutoPost/External/'**.
You will be greeted by german prompts, that were implemented for testing purposes. Feel free to alter them to your will.

Before running the application, ensure you have the following credentials:

- `THREADS_ACCESS_TOKEN` 
- `THREADS_USER_ID`
- `ANTHROPIC_API_KEY` (optional, but highly recommended)
- `REDDIT_CLIENT_ID` (optional)
- `REDDIT_CLIENT_SECRET` (optional)
- `REDDIT_USER_AGENT` (optional)

These parameters need to be set within an `.env` file at the root of the project.

Example `.env` file:

```
THREADS_ACCESS_TOKEN=your_token_here
THREADS_USER_ID=your_user_id_here
ANTHROPIC_API_KEY=your_api_key_here
REDDIT_CLIENT_ID=your_client_id_here
REDDIT_CLIENT_SECRET=your_client_secret_here
REDDIT_USER_AGENT=your_user_agent_here
```

## Usage

Run the CLI using:

```sh
php bin/console app:threads <action> [value] [context] [extra]
```

### Available Commands

| Command       | Description                                             |
|---------------|---------------------------------------------------------|
| `list`        | List all existing threads                               |
| `post "text"` | Post a new thread with given text                       |
| `status`      | Check API connection status                             |
| `get ID`      | Retrieve details of a specific thread                   |
| `delete ID`   | Delete a thread by ID                                   |
| `auto-post X` | Start automated posting (X = range like 1-3, 3-5, etc.) |
| `auto-reply`  | Start auto-reply to incoming threads                    |
| `config`      | Modify configuration settings                           |
| `audit`       | Review automated interactions                           |
| `help`        | Show help message                                       |

## Configuration

To manage configuration settings, use:

```sh
php bin/console app:threads config <parameter> <operation> [extra]
```

### Example Configuration Commands

- View current subreddits:
  ```sh
  php bin/console app:threads config subreddits get
  ```
- Add a subreddit:
  ```sh
  php bin/console app:threads config subreddits add politics
  ```
- Remove a subreddit:
  ```sh
  php bin/console app:threads config subreddits remove politics
  ```

## Auto-Post and Auto-Reply

Threadstorm supports automation for both posting and replying to threads.

- **Auto-Post**:
  ```sh
  php bin/console app:threads auto-post 3-5
  ```
  This continuously posts 3-5 new threads in random intervals within a 24-hour timespan.

- **Auto-Reply**:
  ```sh
  php bin/console app:threads auto-reply
  ```
  This automatically scrapes and replies to comments on your own threads in random intervals between 15 and 300 minutes.

## Audit Functionality

To review automated activities:

```sh
php bin/console app:threads audit
```

You will be prompted to select either **Auto-Post** or **Auto-Reply** for audit review.

## Error Handling

If an error occurs, the CLI provides clear messages:

```sh
❌ A thread ID is required to retrieve a thread.
```

To troubleshoot, check your command syntax using `help`:

```sh
php bin/console app:threads help
```


## Disclaimer

### Important Notice

**Threadstorm CLI** is an unofficial tool designed to interact with the Threads API. **This tool has not been reviewed, endorsed, or authorized by Meta or any affiliated entities.**

### Security Warning

This project was developed with a primary focus on functionality rather than security. As a result, using this tool **may expose users to account bans, restrictions, or violations of the Threads Terms of Service (TOS).** Additionally, improper use of API credentials and automation features **could lead to unintended consequences, including account suspension.**

### User Responsibility

By using **Threadstorm CLI**, you acknowledge that:

- You assume full responsibility for any actions taken while using the tool.
- You understand that automating interactions on Threads **may** violate the platform's TOS.
- You are aware of potential risks, including **API bans, account restrictions, or permanent suspension.**
- You are solely responsible for securely handling your API credentials and personal data.

### Recommendation

- **Proceed with caution.** Use at your own risk and consider testing on a secondary account.
- **Do not misuse automated features** in a way that could be considered spammy, abusive, or harmful.
- **Stay informed** about the latest Threads API policies and platform regulations.

### No Liability

The developers and contributors of **Threadstorm CLI** hold **no liability** for any damages, losses, or consequences resulting from the use of this tool. Use it responsibly and in compliance with applicable laws and platform regulations.

For more details, refer to the Threads Terms of Service and API usage guidelines before using this software.

## License

This project is licensed under the MIT License. See `LICENSE` for details.


## Contributing

1. Fork the repository
2. Create a new branch (`feature-new-functionality`)
3. Commit your changes
4. Push to your branch and create a Pull Request

