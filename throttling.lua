#!lua name=discrete_window_throttling

-- Helper functions --

local function log2(x)
    return math.log(x) / math.log(2)
end

local function now()
    local r = redis.call('TIME')
    return tonumber(r[1]) * 1000000 + tonumber(r[2])
end

-- Private functions --

local MAX_SAFE_INTEGER = 2 ^ 53 - 1
local HEADER_WIDTH = 64 + 64

local function header_read(key)
    local r = redis.call('BITFIELD_RO', key,
        'GET', 'i64', 0,
        'GET', 'i64', 64)
    return r[1], r[2]
end

local function reallocate(key, frame_count, frame_width, ttl)
    redis.call('SET', key, '', 'PX', ttl)
    redis.call('SETBIT', key, HEADER_WIDTH + frame_count * frame_width - 1, 0)
end

local function clear_frames(key, frame_count, frame_width, first, last) -- TODO Bulk BITFIELD calls.
    local frame_encoding = 'u' .. frame_width
    local old_sum = 0

    for frame = first, last do
        local frame_offset = HEADER_WIDTH + (frame % frame_count) * frame_width
        old_sum = old_sum + redis.call('BITFIELD', key, 'OVERFLOW', 'FAIL', 'SET', frame_encoding, frame_offset, 0)[1]
    end

    return old_sum
end

-- Straightly appends EVENT_COUNT to key, no limit check performed. When EVENT_COUNT == 0 only refreshes index & total (cleans expired frames aswell).
local function dwthrottler_events(key, frame_count, frame_length, frame_width, event_count)
    local current_frame = math.floor(now() / frame_length) -- Current absolute frame index (since beginning of the time).
    local stored_frame, stored_total = header_read(key) -- Stored absolute frame index and total sum of events.
    local current_total = stored_total + event_count

    local frame_encoding = 'u' .. frame_width
    local frame_jump = current_frame - stored_frame
    local ttl = math.ceil(frame_length * frame_count / 1000)

    if stored_frame == 0 or frame_jump >= frame_count then -- Resetting whole window or allocating anew.
        reallocate(key, frame_count, frame_width, ttl)
        stored_total = 0 -- Original value has been rewritten.
        current_total = event_count
    elseif stored_frame > 0 and frame_jump > 0 then -- Resetting counters of jumped over frames.
        current_total = current_total - clear_frames(key, frame_count, frame_width, stored_frame + 1, current_frame)
    end

    if event_count > 0 then
        local frame_offset = HEADER_WIDTH + (current_frame % frame_count) * frame_width -- Updating current frame counter.
        redis.call('BITFIELD', key, 'OVERFLOW', 'FAIL', 'INCRBY', frame_encoding, frame_offset, event_count)
        redis.call('PEXPIRE', key, ttl) -- Updating the data field TTL.
    end

    if frame_jump > 0 then -- Updating pivot frame index.
        redis.call('BITFIELD', key, 'OVERFLOW', 'FAIL', 'SET', 'i64', 0, current_frame)
    end
    if current_total ~= stored_total then -- Updating total.
        redis.call('BITFIELD', key, 'OVERFLOW', 'FAIL', 'SET', 'i64', 64, current_total)
    end

    return { current_total, stored_total + event_count - current_total } -- Returning current & expired (if possible) amounts of events.
end

-- Calculates ETA for requested EVENT_COUNT. Refreshes index & total if required.
local function dwthrottler_rw_eta(key, frame_count, frame_length, frame_width, limit, event_count)
    local now_ts = now()
    local current_frame = math.floor(now_ts / frame_length)
    local stored_frame, stored_total = header_read(key)
    local frame_jump = current_frame - stored_frame

    if event_count <= 0 or stored_frame == 0 or frame_jump >= frame_count then -- No events or no data or all expired.
        return now_ts                                                          -- So just returning current timestamp.
    end

    local current_total = stored_total
    if frame_jump > 0 then -- Clear expired frames and recalculate total.
        current_total = current_total - clear_frames(key, frame_count, frame_width, stored_frame + 1, current_frame)
        redis.call('BITFIELD', key, 'OVERFLOW', 'FAIL', 'SET', 'i64', 0, current_frame, 'SET', 'i64', 64, current_total)
    end

    local frame_encoding = 'u' .. frame_width
    local underflow = limit - current_total
    local future_delta = 0

    -- Walking forward from oldest stored frame to current but excluding jumed over.
    for frame_index = current_frame + 1, current_frame + frame_count - frame_jump do
        if underflow >= event_count then return now_ts + future_delta end -- Enough space for requested amount of events.
        local frame_offset = HEADER_WIDTH + (frame_index % frame_count) * frame_width
        local frame_value = redis.call('BITFIELD', key, 'GET', frame_encoding, frame_offset)[1]
        underflow = underflow + frame_value
        future_delta = future_delta + frame_length
    end

    return now_ts + future_delta -- At this point all filled frames should be discarded.
end

-- Calculates ETA for requested EVENT_COUNT, changes nothing. May be slower than caching version but replicas-safe.
local function dwthrottler_ro_eta(key, frame_count, frame_length, frame_width, limit, event_count)
    local now_ts = now()
    local current_frame = math.floor(now_ts / frame_length)
    local stored_frame = header_read(key)
    local frame_jump = current_frame - stored_frame

    if event_count > limit then -- Too much events requested, timestamp will never be reached.
        return nil
    elseif event_count <= 0 or stored_frame == 0 or frame_jump >= frame_count then -- No events or no data or all expired.
        return now_ts -- So just returning current timestamp.
    end

    local frame_encoding = 'u' .. frame_width
    local future_delta = frame_length * (frame_count - frame_jump)
    local sum = 0

    -- Walking backwards from current frame to oldest stored but excluding jumed over.
    for frame_index = current_frame - frame_jump, current_frame - frame_count + 1, -1 do
        local frame_offset = HEADER_WIDTH + (frame_index % frame_count) * frame_width
        local frame_value = redis.call('BITFIELD_RO', key, 'GET', frame_encoding, frame_offset)[1]
        if sum + frame_value <= limit - event_count then
            future_delta = future_delta - frame_length
            sum = sum + frame_value
        else
            break
        end
    end

    return now_ts + future_delta
end

-- Module helpers --

local function parse_base(keys, args)
    local key = keys[1]                       -- A field where all data have to be stored. TODO Validate not empty.
    local frame_count = tonumber(args[1]) + 1 -- One extra frame to avoiding data loss. TODO Validate integer and > 0.
    local frame_length = tonumber(args[2])    -- Single window frame duration in microseconds. TODO Validate integer and > 0.
    local frame_width = tonumber(args[3])     -- Bits required for each frame counter. TODO Validate integer and > 0.
    -- local frame_width = math.ceil(log2(limit + 1)) -- Bits required for each frame counter.
    -- local window_length = frame_length * frame_count -- Total window duration in microseconds. TODO Validate safe integer.
    return { key, frame_count, frame_length, frame_width }
end

local function parse_eta(keys, args)
    local limit = tonumber(args[4]) -- Maximum amount of events allowed within the window. TODO Validate safe integer and > 0.
    local event_count = args[5] and tonumber(args[5]) or 1 -- Amount of events are demanded (at closest point). TODO Validate safe integer and 0 <= value <= limit.
    local arguments = parse_base(keys, args)
    table.insert(arguments, limit)
    table.insert(arguments, event_count)
    return arguments
end

-- Module exports --

-- FCALL dwthrottler_event 1 KEY FRAME_COUNT FRAME_LENGTH FRAME_WIDTH EVENT_COUNT=1 => { total, expired }
redis.register_function('dwthrottler_events', function(keys, args)
    local event_count = args[4] and tonumber(args[4]) or 1 -- Number of events to be logged in current frame. TODO Validate safe integer and 0 <= value <= limit.
    if event_count < 0 then event_count = 0 end
    local arguments = parse_base(keys, args)
    table.insert(arguments, event_count)
    return dwthrottler_events(unpack(arguments))
end)

-- FCALL dwthrottler_try 1 KEY FRAME_COUNT FRAME_LENGTH FRAME_WIDTH LIMIT EVENT_COUNT=1 => { taken, eta }
redis.register_function('dwthrottler_try', function(keys, args)
    local key, frame_count, frame_length, frame_width, limit, event_count = unpack(parse_eta(keys, args))
    if event_count > limit then return nil end -- Too much events requested, timestamp will never be reached.
    local eta = dwthrottler_rw_eta(key, frame_count, frame_length, frame_width, limit, event_count)
    if eta > now() or event_count <= 0 then -- Can't log event or just check (with caching).
        return { 0, eta }
    else -- Logging event(s).
        dwthrottler_events(key, frame_count, frame_length, frame_width, event_count)
        return { event_count, eta }
    end
end)

-- FCALL dwthrottler_fill 1 KEY FRAME_COUNT FRAME_LENGTH FRAME_WIDTH LIMIT EVENT_COUNT=1 => { taken, eta }
redis.register_function('dwthrottler_fill', function(keys, args)
    local key, frame_count, frame_length, frame_width, limit, event_count = unpack(parse_eta(keys, args))
    local eta = now() -- Just for consistency.
    if event_count > limit then -- Too much events requested, timestamp will never be reached.
        return nil
    elseif event_count <= 0 then -- No work required.
        return { 0, eta }
    end

    local total = dwthrottler_events(key, frame_count, frame_length, frame_width, 0)[1] -- Only getting actual total.
    local taken = 0
    if limit - total > 0 then -- If there are free space.
        taken = math.min(event_count, limit - total)
        dwthrottler_events(key, frame_count, frame_length, frame_width, taken)
    end
    local left = event_count - taken
    if left > 0 then -- If there are events left.
        eta = dwthrottler_rw_eta(key, frame_count, frame_length, frame_width, limit, left)
    end
    return { taken, eta }
end)

-- FCALL_RO dwthrottler_now 0 => microseconds
redis.register_function{
    function_name = 'dwthrottler_now',
    callback = function(keys, args) return now() end,
    flags = { 'no-writes' }
}

-- FCALL_RO dwthrottler_eta 1 KEY FRAME_COUNT FRAME_LENGTH FRAME_WIDTH LIMIT EVENT_COUNT=1 => eta
redis.register_function{
    function_name = 'dwthrottler_eta',
    callback = function(keys, args) return dwthrottler_ro_eta(unpack(parse_eta(keys, args))) end,
    flags = { 'no-writes' }
}
