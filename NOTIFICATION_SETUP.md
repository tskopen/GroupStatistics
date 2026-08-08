# Push Notification Setup Guide

Your Squadron Tracker has push notifications infrastructure, but needs configuration to actually deliver.

## Current Status
- ✅ VAPID keys generated
- ✅ User subscriptions captured
- ✅ Push service code deployed (PHP)
- ❌ FCM needs authentication configured (Service Account or Legacy Server Key)
- ⚠️ WNS works but requires proper Windows subscription

## How to Enable FCM Notifications (Android/Chrome)

### Option A: Modern Firebase V1 API + Service Account (Recommended)

**Step 1: Generate Service Account JSON**
1. Go to [Firebase Console](https://console.firebase.google.com)
2. Select your project
3. **Project Settings** → **Service Accounts** tab
4. Click **Manage Service Accounts** (opens Google Cloud Console)
5. Find your service account: `firebase-adminsdk-xxxxx@your-project.iam.gserviceaccount.com`
6. Click the 3-dot menu → **Manage keys**
7. **Create new key** → **JSON**
8. Download the JSON file

**Step 2: Add to Railway**
1. Open the downloaded JSON file in a text editor
2. Copy the entire contents
3. SSH into Railway or use SFTP
4. Create file: `/data/firebase-service-account.json`
5. Paste the JSON contents
6. Save and close

Or set as environment variable:
- Base64 encode the JSON: `cat firebase-key.json | base64`
- In Railway Variables: `FIREBASE_CREDENTIALS=<base64-encoded-json>`

**Step 3: Test**
- Admin Panel → Send Notifications
- Enter score
- Check Railway logs for: `✓ FCM V1 sent to token...`

### Option B: Legacy Server API Key (if available)

Some older Firebase projects have a Server API Key. If you see it in Firebase Console:

1. Firebase Console → **Project Settings** → **Cloud Messaging**
2. Look for **Server API Key** (below the Sender ID)
3. Copy it
4. In Railway Variables: `FCM_SERVER_KEY=your-server-api-key`
5. Test same as above

## WNS (Windows/Edge)
Should work automatically - no configuration needed. Test by subscribing on Windows/Edge browser.

## Troubleshooting

### "FCM not configured" in logs
- Create `/data/firebase-service-account.json`, OR
- Set `FCM_SERVER_KEY` environment variable

### FCM V1 returns 401
- Service Account credentials invalid
- Re-download JSON from Firebase Console
- Make sure JSON is valid (open in text editor, paste in online JSON validator)

### FCM V1 returns 403 (Permission denied)
- Service Account doesn't have FCM permissions
- Go to Google Cloud Console
- Grant "Firebase Cloud Messaging Agent" role to your service account

### FCM Legacy returns 401
- Server API Key is incorrect or expired
- Generate new one in Firebase Console

### WNS returns 401
- WNS endpoints expire after time (user needs to re-subscribe)
- Have user go to Notifications settings and re-enable

## Files to Check
- Railway logs for delivery status
- `/data/notification-subscriptions.json` - see subscribed endpoints
- `push-service.php` - handles actual delivery
