# Push Notification Setup Guide

Your Squadron Tracker has push notifications infrastructure, but needs configuration to actually deliver.

## Current Status
- ✅ VAPID keys generated
- ✅ User subscriptions captured
- ✅ Push service code deployed (PHP)
- ❌ FCM needs Server API Key
- ⚠️ WNS works but requires proper Windows subscription

## How to Enable FCM Notifications (Android/Chrome)

### Step 1: Get Firebase Server API Key
1. Go to [Firebase Console](https://console.firebase.google.com)
2. Select your project (or create one)
3. Go to **Project Settings** (gear icon)
4. Click **Cloud Messaging** tab
5. Copy the **Server API Key**

### Step 2: Set in Railway
1. Go to Railway Dashboard
2. Select your GroupStatistics service
3. Variables tab
4. Add new variable:
   - Name: `FCM_SERVER_KEY`
   - Value: (paste your Server API Key)
5. Save and redeploy

### Step 3: Test
1. Admin Panel → Send Notifications
2. Select squadron and enter score
3. Check Railway logs for: `✓ FCM sent to token...`

## WNS (Windows/Edge)
WNS notifications should work now with the fixed headers in push-service.php. Test by subscribing on Windows/Edge browser.

## Troubleshooting

### FCM returns 404
- Check `FCM_SERVER_KEY` is set correctly
- Verify it's the "Server API Key" not the Web API Key
- Make sure it's copied completely without spaces

### FCM returns 401
- Server API Key is incorrect or expired
- Generate a new one in Firebase Console

### WNS returns 401
- WNS endpoints expire after time (user needs to re-subscribe)
- Have user go to Notifications settings and re-enable

## Files to Check
- Railway logs for delivery status
- `/data/notification-subscriptions.json` - see subscribed endpoints
- `push-service.php` - handles actual delivery
