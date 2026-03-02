# LatePoint – Date First Booking

A WordPress plugin that reorders the [LatePoint](https://latepoint.com) booking flow so customers **pick a date before selecting a service**. Only services that have availability on the chosen date are shown.

Ideal for venues where every service shares the same work schedule — beach clubs, day spas, event spaces, etc.

## What it does

- Moves the **Date & Time** step before the **Service** step
- Renders the calendar correctly even though no service is selected yet
- On the Service step, **hides fully-booked services** for the chosen date

## Requirements

- WordPress 5.8+
- PHP 7.4+
- LatePoint Pro (tested on v5.x)

## Installation

1. Download the latest zip from [Releases](../../releases)
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. In **LatePoint → Booking Form Editor**, drag **Date & Time** before **Service Selection** and save

Updates will appear automatically in WP Admin → Plugins once a new release is published on GitHub.

## How updates work

The plugin uses [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) to poll this GitHub repo for new releases. When a new release is published with a `.zip` asset, WordPress will show the standard update notification.
