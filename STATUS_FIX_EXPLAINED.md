# Fix: "Why do I always see pending 0?"

## 🐛 **The Problem**

When running monitor scripts, you always saw:
```
Pending: 0
Processing: 188
Completed: 0
```

Even though jobs were being queued, the database never showed any "pending" or "queued" status.

## 🔍 **Root Cause**

The original implementation had **no "queued" status** in the database!

When jobs were added, they were **immediately marked as "processing"**:

```php
// OLD CODE - Wrong!
PrecomputedErosionMap::create([
    'status' => 'processing',  // ❌ Marked as processing immediately
    ...
]);
```

This meant:
- ❌ **No distinction** between queued jobs and actively processing jobs
- ❌ **No "pending" or "queued" status** existed in the database
- ❌ Monitor scripts always showed 0 pending/queued jobs

## ✅ **The Fix**

### **1. Added "queued" Status**

Now jobs start with `status = 'queued'`:

```php
// NEW CODE - Correct!
PrecomputedErosionMap::create([
    'status' => 'queued',  // ✅ Marked as queued initially
    ...
]);
```

### **2. Status Transition Flow**

Jobs now follow this proper lifecycle:

```
📋 queued → ⚙️ processing → ✅ completed
                      ↓
                    ❌ failed
```

**When it happens:**
- **`queued`** - When job is first created (via Laravel)
- **`processing`** - When Celery worker starts the task (callback from Python)
- **`completed`** - When task finishes successfully (callback from Python)
- **`failed`** - When task errors out

### **3. Added Python Callbacks**

**New Endpoint:** `/api/erosion/task-started`

When a Celery worker **picks up** a task, it calls this endpoint to update status from `queued` → `processing`:

```python
# In tasks.py
requests.post("http://localhost/api/erosion/task-started", json={
    'task_id': self.request.id,
    'area_type': area_type,
    'area_id': area_id,
    'year': year
})
```

**Existing Endpoint:** `/api/erosion/task-complete`

When task finishes, updates status to `completed`.

## 📊 **Before vs After**

### **Before Fix**
```
Total: 188
✅ Completed: 0
⚙️  Processing: 188  ← Everything marked as "processing"
📋 Queued: 0        ← Never existed!
```

### **After Fix**
```
Total: 188
✅ Completed: 26
⚙️  Processing: 0 (when no workers active)
📋 Queued: 162     ← Now visible! ✨
```

## 🎯 **What Changed**

### **Files Modified:**

1. **`app/Services/ErosionTileService.php`**
   - Changed `'status' => 'processing'` to `'status' => 'queued'` when creating jobs
   - Added `handleTaskStarted()` method
   - Updated checks to handle both `queued` and `processing` statuses

2. **`app/Http/Controllers/ErosionTileController.php`**
   - Added `taskStarted()` method for callback

3. **`routes/api.php`**
   - Added `POST /api/erosion/task-started` route

4. **`python-gee-service/tasks.py`**
   - Added callback to `/api/erosion/task-started` when task starts
   - Existing callback to `/api/erosion/task-complete` when task finishes

5. **`monitor-precomputation.sh`** (NEW)
   - Proper monitoring script that shows all statuses

### **Database Update:**

Reset orphaned "processing" records to "queued":
```bash
php artisan tinker --execute="
  \App\Models\PrecomputedErosionMap::where('status', 'processing')->update(['status' => 'queued']);
"
```

## ✅ **How to Use**

### **Monitor Progress:**

```bash
cd /var/www/rusle-icarda
./monitor-precomputation.sh
```

**Output shows:**
```
📊 STATUS BREAKDOWN:
Total Jobs:      188
✅ Completed:     26 (13.8%)
⚙️  Processing:    0
📋 Queued:        162  ← Now visible!
❌ Failed:        0

Progress: 13.8%
Remaining: 162 jobs
Est. time: ~3.4 hours (with 4 workers)
```

### **Watch Live Status:**

```bash
# Watch logs
sudo tail -f /var/log/rusle-celery-worker.log

# Check Redis queue
redis-cli llen celery

# Watch database changes
watch -n 5 'cd /var/www/rusle-icarda && php artisan tinker --execute="
  echo \"Completed: \" . \App\Models\PrecomputedErosionMap::where(\"status\", \"completed\")->count();
  echo \" | Processing: \" . \App\Models\PrecomputedErosionMap::where(\"status\", \"processing\")->count();
  echo \" | Queued: \" . \App\Models\PrecomputedErosionMap::where(\"status\", \"queued\")->count();
"'
```

## 📋 **Status Meanings**

| Status | Meaning | Where Set |
|--------|---------|-----------|
| **`queued`** | Job created, waiting for worker | Laravel (when queued) |
| **`processing`** | Worker actively computing | Python (when started) |
| **`completed`** | Successfully finished | Python (when done) |
| **`failed`** | Error occurred | Python (on error) |

## 🎉 **Summary**

**Issue:** Database never tracked "queued" status, always showed "pending: 0"

**Cause:** Jobs were marked as "processing" immediately when created

**Fix:** 
- ✅ Jobs now start with `status = 'queued'`
- ✅ Python callback updates to `'processing'` when work starts
- ✅ Monitor scripts now show correct queued count
- ✅ 162 jobs currently showing as "queued" (not "processing")

**Status:** ✅ **RESOLVED**

---

**Date:** November 2, 2025  
**Impact:** Visual/Monitoring only (no functional changes)  
**User Impact:** Can now see queued jobs count correctly










